// Temporary CI probe: real Qwen3-0.6B ONNX inference on CPU (onnxruntime-node).
import { pipeline, env } from '@huggingface/transformers';

const MODEL = process.env.MODEL || 'onnx-community/Qwen3-0.6B-ONNX';
const prompts = {
  vi: [
    { role: 'system', content: 'Bạn là biên tập viên tiếng Việt. Viết bằng tiếng Việt có dấu, rõ ràng, hữu ích. Không bịa giá, luật, số liệu hoặc thông tin doanh nghiệp.' },
    { role: 'user', content: 'Viết khoảng 200 từ cho phần "Kinh nghiệm chọn xe máy phù hợp khi du lịch Hà Nội". Chỉ trả về đoạn văn, không tiêu đề.' },
  ],
  en: [
    { role: 'system', content: 'You are an English editor. Write clear, useful English. Do not invent prices, laws, statistics or business facts.' },
    { role: 'user', content: 'Write about 200 words for the section "How to choose a motorbike for a Hanoi city trip". Return only paragraphs, no heading.' },
  ],
  outline: [
    { role: 'system', content: 'Bạn là biên tập viên tiếng Việt.' },
    { role: 'user', content: 'Tạo đúng 7 tiêu đề H2 cho bài "Kinh nghiệm thuê xe máy ở Hà Nội cho người mới". Mỗi dòng một tiêu đề, không đánh số, không giải thích.' },
  ],
};

for (const dtype of (process.env.DTYPES || 'q4,q4f16').split(',')) {
  const t0 = Date.now();
  let gen;
  try {
    gen = await pipeline('text-generation', MODEL, { dtype, device: 'cpu' });
  } catch (e) {
    console.log(`LOAD_FAIL dtype=${dtype}: ${e.message}`);
    continue;
  }
  console.log(`LOADED dtype=${dtype} in ${(Date.now() - t0) / 1000}s`);
  for (const [name, messages] of Object.entries(prompts)) {
    for (const thinking of ['kwarg']) {
      let tokens = 0;
      const { TextStreamer } = await import('@huggingface/transformers');
      const streamer = new TextStreamer(gen.tokenizer, { skip_prompt: true, callback_function: () => {}, token_callback_function: () => { tokens++; } });
      const t1 = Date.now();
      const out = await gen(messages, {
        max_new_tokens: name === 'outline' ? 160 : 420,
        do_sample: true, temperature: 0.7, top_p: 0.8, top_k: 20,
        repetition_penalty: Number(process.env.REP || 1.05),
        tokenizer_encode_kwargs: { enable_thinking: false },
        streamer,
      });
      const secs = (Date.now() - t1) / 1000;
      const text = out[0].generated_text.at(-1).content;
      const words = (text.match(/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/gu) || []).length;
      const han = (text.match(/\p{Script=Han}/gu) || []).length;
      console.log(`\n===== ${dtype} ${name} tokens=${tokens} secs=${secs} tok/s=${(tokens / secs).toFixed(2)} words=${words} tokens/word=${(tokens / Math.max(1, words)).toFixed(2)} han=${han}`);
      console.log(text);
    }
  }
  await gen.dispose?.();
}
