/* Browser-local Qwen. Loads open source model only after administrator action.
 * Experimental: external module must be vendored before production deployment.
 */
let generator = null;
const MODEL = 'onnx-community/Qwen3-0.6B-ONNX';
function getText(result) {
  const output = result?.[0]?.generated_text;
  const content = Array.isArray(output) ? output.at(-1)?.content : output;
  if (typeof content !== 'string') throw new Error('Qwen không trả về nội dung hợp lệ.');
  return content.replace(/<think>[\s\S]*?<\/think>/gi, '').replace(/<\|[^>]+?\|>/g, '').trim();
}
self.onmessage = async ({data}) => {
  const {id, action, payload} = data;
  try {
    if (action === 'load') {
      if (!self.navigator?.gpu) throw new Error('WebGPU chưa có trên trình duyệt/thiết bị.');
      const {pipeline} = await import('https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.1');
      generator = await pipeline('text-generation', MODEL, {
        device: 'webgpu', dtype: 'q4f16',
        progress_callback: p => {
          if (p?.status === 'progress' && Number.isFinite(p.progress)) {
            self.postMessage({type:'progress', message:'Tải model: ' + Math.round(p.progress) + '%'});
          }
        },
      });
      self.postMessage({id, ok:true, result:{model:MODEL, device:'webgpu'}});
    } else if (action === 'generate') {
      if (!generator) throw new Error('Model chưa tải xong.');
      if (!Array.isArray(payload?.messages)) throw new Error('Dữ liệu prompt không hợp lệ.');
      const response = await generator(payload.messages, {
        max_new_tokens:460, do_sample:true, temperature:0.7, top_p:0.8,
        top_k:20, repetition_penalty:1.12,
      });
      self.postMessage({id, ok:true, result:getText(response)});
    } else {
      throw new Error('Hành động không hỗ trợ.');
    }
  } catch(e) {
    self.postMessage({id, ok:false, error:String(e?.message ?? e)});
  }
};
