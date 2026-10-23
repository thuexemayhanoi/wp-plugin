/* TEST DOUBLE ONLY — never shipped. Stands in for transformers.min.js in UI tests
 * so the real Worker, orchestration, save flow and WordPress handlers can be
 * exercised without downloading model weights. It does NOT prove model quality.
 * Behaviour switches are injected by the test as `const FAKE = {...}` on line 1.
 */
const options = typeof FAKE === 'object' ? FAKE : {};
const sleep = ms => new Promise(r => setTimeout(r, ms));

export const env = { backends: { onnx: { wasm: {} } }, remoteHost: '', remotePathTemplate: '' };

export class TextStreamer {
  constructor(tokenizer, opts) { this.opts = opts; }
}
export class InterruptableStoppingCriteria {
  constructor() { this.interrupted = false; }
  interrupt() { this.interrupted = true; }
}

const VI = ['xe máy', 'người thuê', 'giấy tờ', 'hợp đồng', 'đặt cọc', 'lộ trình', 'an toàn', 'mũ bảo hiểm', 'bảo dưỡng', 'thời tiết', 'bãi gửi xe', 'nhiên liệu'];
const EN = ['motorbike', 'rental', 'documents', 'contract', 'deposit', 'route', 'safety', 'helmet', 'maintenance', 'weather', 'parking', 'fuel'];
let call = 0;

function sentence(lang, n) {
  const w = lang === 'en' ? EN : VI;
  const a = w[n % w.length], b = w[(n * 7 + 3) % w.length], c = w[(n * 5 + 1) % w.length];
  return lang === 'en'
    ? `Point ${n}: checking the ${a} together with the ${b} helps riders plan the ${c} calmly and avoid surprises.`
    : `Ý ${n}: kiểm tra ${a} cùng với ${b} giúp người đi đường chủ động hơn về ${c} và tránh rủi ro không đáng có.`;
}

export async function pipeline(task, model, opts = {}) {
  if (options.failDevices?.includes(opts.device)) throw new Error(`fake: ${opts.device} backend failed to initialise`);
  if (options.networkError) throw new Error('Failed to fetch');
  const total = 600 * 1048576;
  for (const loaded of [0.25, 0.5, 1].map(f => f * total)) {
    opts.progress_callback?.({ status: 'progress_total', loaded, total, progress: (loaded / total) * 100 });
    await sleep(5);
  }
  const generator = async (messages, gen) => {
    call++;
    if (options.crashOnCall && call === options.crashOnCall) {
      // An uncaught error kills the Worker's task the way an out-of-memory abort does.
      setTimeout(() => { throw new Error('fake: worker crashed (simulated out-of-memory)'); }, 50);
      return new Promise(() => {});
    }
    const prompt = messages.at(-1).content;
    const lang = /^Article: /m.test(prompt) ? 'en' : 'vi';
    let text;
    if (/Liệt kê \d+ tiêu đề|List \d+ section titles/.test(prompt)) {
      text = lang === 'en'
        ? 'Here are the section titles:\n1. **Choosing the right motorbike type**\n2. Documents to prepare before renting\n3. Understanding deposits and contracts\n4. Inspecting the bike at pickup\n5. Riding safely in city traffic\n6. Parking, fuel and daily care\n7. Returning the bike without disputes'
        : 'Dưới đây là 7 tiêu đề:\n1. **Chọn loại xe máy phù hợp nhu cầu**\n2. Giấy tờ cần chuẩn bị khi thuê xe\n3. Hiểu rõ đặt cọc và hợp đồng thuê\n4. Kiểm tra xe kỹ khi nhận bàn giao\n5. Lái xe an toàn trong nội thành\n6. Gửi xe, đổ xăng và bảo quản hằng ngày\n7. Trả xe đúng hẹn, tránh phát sinh';
    } else {
      const want = Number((prompt.match(/(?:khoảng|about) (\d+)/) || [])[1] || 150);
      const parts = [];
      let words = 0;
      for (let i = 0; words < want * (options.shortFactor || 1); i++) {
        const s = sentence(lang, call * 100 + i);
        parts.push(s);
        words += s.split(/\s+/).length;
      }
      text = parts.join(' ').replace(/(.{400,}?\.) /g, '$1\n\n');
      if (options.chineseOnce && call === 3) text = '这是中文内容。' + text;
    }
    const { streamer, stopping_criteria: stop } = gen;
    let out = '';
    for (const piece of text.split(/(?<= )/)) {
      if (stop?.interrupted) break;
      out += piece;
      streamer?.opts?.token_callback_function?.();
      streamer?.opts?.callback_function?.(piece);
      if (options.tokenDelay) await sleep(options.tokenDelay);
    }
    return [{ generated_text: [...messages, { role: 'assistant', content: out }] }];
  };
  generator.tokenizer = {};
  generator.dispose = async () => {};
  return generator;
}
