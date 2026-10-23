self.onmessage = async ({ data }) => {
  try {
    const { pipeline, env, TextStreamer } = await import('./vendor/transformers.min.js');
    env.backends.onnx.wasm.wasmPaths = {
      mjs: new URL('./vendor/ort-wasm-simd-threaded.asyncify.mjs', self.location.href).href,
      wasm: new URL('./vendor/ort-wasm-simd-threaded.asyncify.wasm', self.location.href).href,
    };
    let adapterInfo = null;
    if (self.navigator.gpu) {
      const a = await self.navigator.gpu.requestAdapter();
      adapterInfo = a ? { f16: a.features.has('shader-f16'), fallback: a.info?.isFallbackAdapter } : 'null';
    }
    const t0 = performance.now();
    const gen = await pipeline('text-generation', 'onnx-community/Qwen3-0.6B-ONNX', { device: data.device, dtype: data.dtype });
    const loadSecs = (performance.now() - t0) / 1000;
    let tokens = 0;
    const streamer = new TextStreamer(gen.tokenizer, { skip_prompt: true, callback_function: () => {}, token_callback_function: () => { tokens++; } });
    const t1 = performance.now();
    const res = await gen([{ role: 'user', content: 'Viết 3 câu tiếng Việt giới thiệu Hà Nội.' }], {
      max_new_tokens: 80, do_sample: false, tokenizer_encode_kwargs: { enable_thinking: false }, streamer,
    });
    const genSecs = (performance.now() - t1) / 1000;
    self.postMessage({ type: 'done', device: data.device, dtype: data.dtype, adapterInfo, loadSecs, genSecs, tokens, tps: tokens / genSecs, threads: env.backends.onnx.wasm.numThreads, hc: self.navigator.hardwareConcurrency, text: res[0].generated_text.at(-1).content });
  } catch (e) {
    self.postMessage({ type: 'error', device: data.device, dtype: data.dtype, error: String(e?.stack || e) });
  }
};
