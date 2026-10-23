/* Qwen Local Writer inference Worker (ES module).
 *
 * Runs only inside the plugin's wp-admin screen, after an explicit click. The
 * Transformers.js bundle and ONNX Runtime WebAssembly are served from this
 * plugin (assets/vendor); only model weights are fetched, from the configured
 * model host. Nothing is sent anywhere except those GET requests.
 *
 * The page bootstraps this file through a blob: URL so the Worker inherits the
 * page's cross-origin isolation (needed for multi-threaded WASM). Always resolve
 * relative paths against import.meta.url, never self.location.
 */
// Same ?ver= cache-buster as this file, so plugin updates never mix old and new modules.
const VERSION = new URL(import.meta.url).search;
const { MODEL, classifyError: classify, generationOptions } = await import(new URL('./qlw-core.js' + VERSION, import.meta.url).href);

const VENDOR = new URL('./vendor/', import.meta.url);
const ALLOWED_MODELS = new Set([MODEL.id]);
const HF_HOST = 'https://huggingface.co/';

let runtime = null;
let generator = null;
let active = null;
let stopping = null;

function codedError(code, message) {
  const error = new Error(message);
  error.code = code;
  return error;
}

export async function probe(nav = globalThis.navigator) {
  const info = {
    webgpu: false,
    adapter: null,
    f16: false,
    fallbackAdapter: false,
    isolated: globalThis.crossOriginIsolated === true,
    cores: nav?.hardwareConcurrency || 1,
    deviceMemory: nav?.deviceMemory ?? null,
    wasm: typeof WebAssembly === 'object',
  };
  if (nav?.gpu) {
    info.webgpu = true;
    try {
      const adapter = await nav.gpu.requestAdapter({ powerPreference: 'high-performance' });
      if (adapter) {
        info.adapter = { vendor: adapter.info?.vendor || '', architecture: adapter.info?.architecture || '' };
        info.f16 = adapter.features.has('shader-f16');
        info.fallbackAdapter = adapter.info?.isFallbackAdapter === true || adapter.isFallbackAdapter === true;
        info.maxBufferSize = Number(adapter.limits?.maxBufferSize || 0);
      }
    } catch (error) {
      info.adapterError = String(error?.message || error);
    }
  }
  return info;
}

/** Ordered list of {device, dtype} to try. Software WebGPU adapters are slower than WASM, so auto skips them. */
export function candidates(info, preference = 'auto', wasmDtype = 'q4') {
  const gpu = info.adapter ? [{ device: 'webgpu', dtype: info.f16 ? 'q4f16' : 'q4' }] : [];
  const cpu = info.wasm ? [{ device: 'wasm', dtype: wasmDtype === 'q4f16' ? 'q4f16' : 'q4' }] : [];
  if (preference === 'webgpu') return gpu;
  if (preference === 'wasm') return cpu;
  return info.adapter && !info.fallbackAdapter ? [...gpu, ...cpu] : cpu;
}

export function modelSource(config) {
  if (!ALLOWED_MODELS.has(config?.model)) throw codedError('BAD_REQUEST', 'Model không nằm trong danh sách cho phép.');
  if (!/^(?:main|[0-9a-f]{40})$/.test(config.revision || '')) throw codedError('BAD_REQUEST', 'Model revision không hợp lệ.');
  if (!config.selfHostedBase) return { remoteHost: HF_HOST, remotePathTemplate: '{model}/resolve/{revision}/' };
  const base = new URL(config.selfHostedBase);
  if (base.origin !== new URL(import.meta.url).origin || base.protocol !== new URL(import.meta.url).protocol) {
    throw codedError('BAD_REQUEST', 'Thư mục model tự lưu trữ phải cùng tên miền với WordPress.');
  }
  return { remoteHost: base.href.replace(/\/?$/, '/'), remotePathTemplate: '{model}/' };
}

async function loadRuntime() {
  if (runtime) return runtime;
  runtime = await import(new URL('transformers/transformers.min.js' + VERSION, VENDOR).href);
  const { env } = runtime;
  env.allowLocalModels = false;
  env.allowRemoteModels = true;
  env.useBrowserCache = true;
  env.backends.onnx.wasm.wasmPaths = {
    mjs: new URL('onnxruntime-web/ort-wasm-simd-threaded.asyncify.mjs', VENDOR).href,
    wasm: new URL('onnxruntime-web/ort-wasm-simd-threaded.asyncify.wasm', VENDOR).href,
  };
  // numThreads stays at ONNX Runtime's default (half the logical cores, max 4): measured in CI,
  // 3 threads on a 4-vCPU/2-core runner ran at ~1.5 token/s versus ~2.6 with the default 2.
  return runtime;
}

async function load(payload, post) {
  const config = payload?.config || {};
  const source = modelSource(config);
  const info = await probe();
  const plan = candidates(info, payload?.device || 'auto', payload?.wasmDtype);
  if (!plan.length) {
    throw codedError(info.webgpu ? 'NO_ADAPTER' : 'NO_WEBGPU', info.webgpu ? 'WebGPU không có GPU adapter phù hợp.' : 'Trình duyệt không hỗ trợ WebGPU.');
  }
  const { pipeline, env } = await loadRuntime();
  env.remoteHost = source.remoteHost;
  env.remotePathTemplate = source.remotePathTemplate;
  if (generator) await unload();
  const attempts = [];
  for (const choice of plan) {
    const started = performance.now();
    try {
      post({ type: 'status', message: `Đang khởi tạo ${choice.device.toUpperCase()} (${choice.dtype})…`, choice });
      generator = await pipeline('text-generation', config.model, {
        device: choice.device,
        dtype: choice.dtype,
        revision: config.revision,
        progress_callback: event => {
          if (event?.status === 'progress_total') {
            post({ type: 'download', loaded: event.loaded, total: event.total, progress: event.progress });
          }
        },
      });
      active = { ...choice, model: config.model, revision: config.revision, seconds: Math.round((performance.now() - started) / 100) / 10 };
      return { ...active, info, attempts };
    } catch (error) {
      const message = String(error?.message || error);
      const code = classify(message);
      attempts.push({ ...choice, error: message.slice(0, 300) });
      generator = null;
      // Download and storage problems will not be solved by another backend.
      if (code === 'NETWORK' || code === 'STORAGE' || code === 'MODEL_FILES') throw codedError(code, message);
    }
  }
  const last = attempts.at(-1)?.error || 'unknown';
  const error = codedError(classify(last) || 'MODEL_LOAD', 'Không khởi tạo được Qwen: ' + attempts.map(a => `${a.device}/${a.dtype}: ${a.error}`).join(' | '));
  error.attempts = attempts;
  throw error;
}

async function generate(payload, post, id) {
  if (!generator) throw codedError('NOT_LOADED', 'Model chưa tải xong.');
  const messages = payload?.messages;
  const valid = Array.isArray(messages) && messages.length <= 8 && messages.every(m =>
    m && ['system', 'user', 'assistant'].includes(m.role) && typeof m.content === 'string' && m.content.length <= 40000);
  if (!valid) throw codedError('BAD_REQUEST', 'Dữ liệu prompt không hợp lệ.');
  const { TextStreamer, InterruptableStoppingCriteria } = runtime;
  stopping = new InterruptableStoppingCriteria();
  let tokens = 0;
  const streamer = new TextStreamer(generator.tokenizer, {
    skip_prompt: true,
    skip_special_tokens: true,
    callback_function: chunk => post({ type: 'token', id, text: chunk, tokens }),
    token_callback_function: () => { tokens++; },
  });
  const started = performance.now();
  const output = await generator(messages, {
    ...generationOptions(payload.params),
    streamer,
    stopping_criteria: stopping,
  });
  const interrupted = stopping.interrupted;
  stopping = null;
  const content = output?.[0]?.generated_text?.at?.(-1)?.content;
  if (typeof content !== 'string') throw codedError('GENERATION', 'Qwen không trả về nội dung hợp lệ.');
  const seconds = (performance.now() - started) / 1000;
  return { text: content, tokens, seconds: Math.round(seconds * 10) / 10, tokensPerSecond: seconds > 0 ? Math.round(tokens / seconds * 10) / 10 : 0, interrupted };
}

async function unload() {
  const old = generator;
  generator = null;
  active = null;
  await old?.dispose?.();
  return { unloaded: true };
}

async function clearCache() {
  await unload();
  const removed = typeof caches === 'object' ? await caches.delete('transformers-cache') : false;
  return { removed };
}

export function handle(post) {
  return async ({ data }) => {
    const { id, action, payload } = data || {};
    if (action === 'interrupt') {
      stopping?.interrupt();
      return;
    }
    try {
      let result;
      if (action === 'probe') result = await probe();
      else if (action === 'load') result = await load(payload, post);
      else if (action === 'generate') result = await generate(payload, post, id);
      else if (action === 'unload') result = await unload();
      else if (action === 'clear-cache') result = await clearCache();
      else throw codedError('BAD_REQUEST', 'Hành động không hỗ trợ.');
      post({ id, ok: true, result });
    } catch (error) {
      const message = String(error?.message ?? error);
      post({ id, ok: false, error: message, code: error?.code || classify(message), attempts: error?.attempts });
    }
  };
}

if (typeof WorkerGlobalScope !== 'undefined' && self instanceof WorkerGlobalScope) {
  self.onmessage = handle(message => self.postMessage(message));
  self.postMessage({ type: 'ready', version: VERSION });
}
