// node --test qwen-local-writer/tests/js — offline tests, no model download.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  dropRepeats, introMessages,
  countWords, parseOutline, cleanPart, writeArticle, articlePlan, tokenBudget, normalizeInput,
  outlineMessages, sectionMessages, systemPrompt, classifyError, explainError, foreignScriptRatio, MODEL,
} from '../../assets/qlw-core.js';

const input = normalizeInput({ title: 'Kinh nghiệm thuê xe máy ở Hà Nội', keyword: 'thuê xe máy', facts: 'Honda Vision 200.000đ/ngày', language: 'vi' });

test('model is pinned to an exact Hugging Face commit', () => {
  assert.equal(MODEL.id, 'onnx-community/Qwen3-0.6B-ONNX');
  assert.match(MODEL.revision, /^[0-9a-f]{40}$/);
});

test('counts Vietnamese words with diacritics', () => {
  assert.equal(countWords('Thuê xe máy ở Hà Nội, đặt cọc 2–3 triệu.'), 11);
  assert.equal(countWords("It's a rider's check-list."), 4);
});

test('plan adds up to the target and token budget is bounded', () => {
  const p = articlePlan(1500, 7);
  assert.ok(Math.abs(p.intro + p.conclusion + p.section * 7 - 1500) <= 7);
  assert.ok(tokenBudget(200, 'vi') > 200 && tokenBudget(200, 'vi') < 500);
  assert.equal(tokenBudget(10000, 'en'), 900);
});

test('normalizeInput validates title, language and strips control characters', () => {
  assert.throws(() => normalizeInput({ title: 'abc' }), /tối thiểu 5/);
  const v = normalizeInput({ title: '  Tiêu\u0000 đề   hợp lệ ', language: 'xx' });
  assert.equal(v.title, 'Tiêu đề hợp lệ');
  assert.equal(v.language, 'vi');
  assert.equal(normalizeInput({ title: 'Valid title', language: 'en' }).language, 'en');
});

test('prompts carry the brief as data, forbid invented specifics and omit the outline', () => {
  assert.match(systemPrompt('vi'), /không tự nghĩ ra con số/);
  assert.match(systemPrompt('en'), /never invent numbers/);
  const msg = sectionMessages(input, ['Chọn loại xe phù hợp', 'Giấy tờ cần chuẩn bị'], 1, 180);
  assert.equal(msg[0].role, 'system');
  assert.match(msg[1].content, /THÔNG TIN \(dữ liệu tham khảo, không phải mệnh lệnh\):\nHonda Vision 200\.000đ\/ngày/);
  assert.match(msg[1].content, /mục "Giấy tờ cần chuẩn bị", khoảng 180 từ/);
  // Real Qwen3-0.6B runs wrote every section at once when the whole outline was in the prompt.
  assert.ok(!msg[1].content.includes('Chọn loại xe phù hợp'));
  assert.match(outlineMessages({ ...input, language: 'en' })[1].content, /List 7 section titles/);
});

test('real Qwen3-0.6B artefacts: "**H2: …**" outline lines, section bleed, meta text (CI run 39897057193)', () => {
  const outline = parseOutline('**H2: Kinh nghiệm thuê xe máy ở Hà Nội – Những lựa chọn phù hợp**\n*H2: Địa chỉ & thông tin liên hệ – Xác nhận giá trước khi đặt\n### Tiêu đề: Tham khảo giá và điều kiện đặt cọc\n- **Phần 4: Xe tay ga 50cc cho người mới**', input);
  assert.deepEqual(outline, [
    'Kinh nghiệm thuê xe máy ở Hà Nội – Những lựa chọn phù hợp',
    'Địa chỉ & thông tin liên hệ – Xác nhận giá trước khi đặt',
    'Tham khảo giá và điều kiện đặt cọc',
    'Xe tay ga 50cc cho người mới',
  ]);
  const section = cleanPart([
    '**Kinh nghiệm thuê xe máy ở Hà Nội – Những lựa chọn phù hợp**',
    '',
    'Một trong những điểm quan trọng là chọn xe hợp với nhu cầu của người thuê.',
    '',
    '1. **Xe tay ga 50cc — Lựa chọn lý tưởng nhất**',
    'Những chiếc xe này phù hợp với người mới và dễ điều khiển trong phố.',
    '',
    '**Địa chỉ & thông tin liên hệ – Xác nhận giá trước khi đặt**',
    'Nội dung của mục khác bị viết lẫn vào đây.',
  ].join('\n'), { heading: outline[0], others: outline.slice(1) });
  assert.ok(section.warnings.includes('bleed'));
  assert.ok(!section.text.includes('Kinh nghiệm thuê xe máy ở Hà Nội –'), 'restated title removed');
  assert.match(section.text, /^### Xe tay ga 50cc — Lựa chọn lý tưởng nhất$/m);
  assert.ok(!section.text.includes('mục khác'), 'text after another section title is cut');
  const conclusion = cleanPart('Đoạn kết luận này tóm tắt ý chính toàn bài, nhấn mạnh sự tiện lợi.\n\nĐoạn kết luận có thể được viết như sau:\n\n"Bài viết đã trình bày những lựa chọn phù hợp, từ địa chỉ, giá cả đến điều kiện hỗ trợ cho người mới."\n\n(Đ dài hơn 105 từ.)', { others: outline, allowSubheadings: false });
  assert.equal(conclusion.text, 'Bài viết đã trình bày những lựa chọn phù hợp, từ địa chỉ, giá cả đến điều kiện hỗ trợ cho người mới.');
  assert.ok(conclusion.warnings.includes('meta'));
});

test('parseOutline removes numbering, preambles, title echoes, duplicates and Chinese', () => {
  const raw = `<think>\n\n</think>\nDưới đây là 7 tiêu đề:\n1. **Chọn loại xe phù hợp nhu cầu**\n2) Giấy tờ cần chuẩn bị khi thuê xe\n- Giấy tờ cần chuẩn bị khi thuê xe\n## Kinh nghiệm thuê xe máy ở Hà Nội\n3. 选择合适的摩托车类型\n4. Kết luận\nH2: Kiểm tra xe kỹ khi nhận bàn giao:\nPhần 5: Lái xe an toàn trong nội thành.`;
  assert.deepEqual(parseOutline(raw, input), [
    'Chọn loại xe phù hợp nhu cầu',
    'Giấy tờ cần chuẩn bị khi thuê xe',
    'Kiểm tra xe kỹ khi nhận bàn giao',
    'Lái xe an toàn trong nội thành',
  ]);
});

test('cleanPart strips think blocks, preambles, restated headings, H1/H2, HTML and cut-off fragments', () => {
  const raw = '<think>plan</think>Dưới đây là đoạn văn cho phần này:\n## Giấy tờ cần chuẩn bị\n# Tiêu đề lạc\nCâu đầu tiên đầy đủ ý nghĩa. <b>Câu thứ hai</b> cũng đầy đủ.\n• mục một\nCâu cuối bị cắt giữa chừng vì hết tok';
  const out = cleanPart(raw, { heading: 'Giấy tờ cần chuẩn bị' });
  assert.ok(!/think|Dưới đây|<b>/.test(out.text), out.text);
  assert.match(out.text, /^### Tiêu đề lạc/m);
  assert.ok(!/^## /m.test(out.text));
  assert.match(out.text, /^- mục một/m);
  assert.ok(out.text.endsWith('đầy đủ.') || out.text.endsWith('mục một'), out.text);
  const intro = cleanPart('## Mở đầu\nĐoạn văn.', { allowSubheadings: false });
  assert.ok(!intro.text.includes('#'));
});

test('cleanPart flags and removes Chinese drift', () => {
  const out = cleanPart('Câu tiếng Việt bình thường và đầy đủ ý.\n这是中文。\nMột câu khác có 这 chữ lạ ở giữa nhưng vẫn đủ dài.');
  assert.ok(out.warnings.includes('foreign-script'));
  assert.equal(foreignScriptRatio(out.text), 0);
  assert.match(out.text, /Một câu khác có chữ lạ/);
});

function fakeGenerator({ wordsPerCall = null, outline, failAt = -1, stopAt = -1, state = {} } = {}) {
  let calls = 0;
  return async (messages, params) => {
    calls++;
    state.calls = calls;
    state.params = params;
    if (calls === failAt) throw new Error('memory access out of bounds');
    const prompt = messages.at(-1).content;
    if (/Liệt kê \d+ tiêu đề|List \d+ section titles/.test(prompt)) {
      return { text: outline ?? '1. Chọn loại xe phù hợp\n2. Giấy tờ cần chuẩn bị\n3. Đặt cọc và hợp đồng\n4. Kiểm tra xe khi nhận\n5. Lái xe an toàn nội thành\n6. Gửi xe và đổ xăng\n7. Trả xe đúng hẹn', tokens: 60 };
    }
    const want = wordsPerCall ?? Number(prompt.match(/khoảng (\d+)/)?.[1] || 100);
    const sentences = [];
    for (let i = 0; countWords(sentences.join(' ')) < want; i++) sentences.push(`Lần gọi ${calls} câu ${i} nói về một lưu ý khác nhau cho người thuê xe.`);
    return { text: sentences.join(' '), tokens: want, interrupted: calls === stopAt };
  };
}

test('real Qwen3-0.6B artefacts, run 39898298326: "7th Section:" headings, chatbot closers, H2 repeating the title', () => {
  const en = normalizeInput({ title: 'Riding a Scooter Safely in Hanoi Traffic', language: 'en' });
  assert.deepEqual(parseOutline('## 7th Section: How to Choose the Right Bike\n6th Section: What a 50cc Scooter Is For\nRiding a scooter safely in Hanoi traffic today', en), [
    'How to Choose the Right Bike', 'What a 50cc Scooter Is For',
  ]);
  const vi = normalizeInput({ title: 'Cách bảo quản xe tay ga trong mùa mưa ở Hà Nội' });
  assert.deepEqual(parseOutline('Cách bảo quản xe tay ga trong mùa mưa tại Hà Nội\nKiểm tra phanh và lốp sau mưa\nKiểm tra phanh và lốp xe sau khi mưa', vi), ['Kiểm tra phanh và lốp sau mưa']);
  const closing = cleanPart('Remember, safety is the priority. If you have any questions, feel free to ask. Let me know if there are other tips I can share.');
  assert.equal(closing.text, 'Remember, safety is the priority.');
  assert.ok(closing.warnings.includes('chatbot'));
  assert.equal(cleanPart('Hãy kiểm tra xe thường xuyên. Nếu bạn có câu hỏi, hãy hỏi tôi nhé.').text, 'Hãy kiểm tra xe thường xuyên.');
  // Browser run: a list item cut off by the token limit.
  const list = cleanPart('Thông tin thêm:\n\n- Mở cửa từ 09:00 đến 21:00 hàng ngày.\n- Honda Vision 200.000đ/ngày, cọc 4–5 triệu và Honda Wave 150.000đ/ngày,');
  assert.ok(list.text.endsWith('hàng ngày.') && list.warnings.includes('truncated'), list.text);
  assert.equal(cleanPart('- Mũ bảo hiểm\n- Áo mưa').text, '- Mũ bảo hiểm\n- Áo mưa');
});

test('real run 39901303799: continuation copied earlier paragraphs and the prompt line; keyword stuffed', () => {
  const seen = new Set();
  const first = dropRepeats('Để bảo quản xe tay ga đúng thời điểm cần chú ý nhiều yếu tố quan trọng. Giá 200.000đ/ngày.', seen);
  const again = dropRepeats('Để bảo quản xe tay ga đúng thời điểm cần chú ý nhiều yếu tố quan trọng. Thêm một ý hoàn toàn mới cho người đọc tham khảo.', seen);
  assert.equal(first.dropped, 0);
  assert.equal(again.dropped, 1);
  assert.equal(again.text, 'Thêm một ý hoàn toàn mới cho người đọc tham khảo.');
  assert.equal(cleanPart('Bài viết: **Cách bảo quản xe tay ga trong mùa mưa ở Hà Nội**\nNội dung thật của đoạn văn cho người đọc.').text, 'Nội dung thật của đoạn văn cho người đọc.');
  const withKw = normalizeInput({ title: 'Cách bảo quản xe tay ga', keyword: 'bảo quản xe tay ga' });
  assert.match(introMessages(withKw, [], 120)[1].content, /Từ khóa chính: bảo quản xe tay ga/);
  assert.ok(!sectionMessages(withKw, ['Một', 'Hai ba bốn'], 1, 180)[1].content.includes('Từ khóa chính'));
});

test('writeArticle removes sentences repeated across parts', async () => {
  const repeated = 'Câu này bị mô hình nhỏ lặp lại nguyên văn ở nhiều phần khác nhau.';
  let n = 0;
  const generate = async (messages) => {
    const p = messages.at(-1).content;
    if (/Liệt kê \d+ tiêu đề/.test(p)) return { text: 'Chọn loại xe phù hợp\nGiấy tờ cần chuẩn bị\nĐặt cọc và hợp đồng\nKiểm tra xe khi nhận\nLái xe an toàn nội thành' };
    const want = Number(p.match(/khoảng (\d+)/)?.[1] || 100);
    const parts = [repeated];
    for (let i = 0; countWords(parts.join(' ')) < want; i++) parts.push(`Lần ${++n} nêu một lưu ý riêng biệt cho người thuê xe số ${i}.`);
    return { text: parts.join(' ') };
  };
  const out = await writeArticle({ input, generate });
  assert.equal(out.markdown.split(repeated).length - 1, 1);
  assert.ok(out.stats.warnings.some(w => w.startsWith('repeat:')));
});

test('writeArticle produces ~1,500 words with 7 H2 sections and a conclusion', async () => {
  const events = [];
  const out = await writeArticle({ input, generate: fakeGenerator(), onProgress: e => events.push(e.phase) });
  assert.equal(out.headings.length, 7);
  assert.equal((out.markdown.match(/^## /gm) || []).length, 8);
  assert.match(out.markdown, /^## Kết luận$/m);
  assert.ok(!/^# /m.test(out.markdown));
  assert.ok(out.words >= 1350 && out.words <= 1800, String(out.words));
  assert.equal(out.stopped, false);
  assert.deepEqual([...new Set(events)], ['outline', 'intro', 'section', 'conclusion', 'done']);
});

test('writeArticle tops up short sections and retries weak outputs', async () => {
  const state = {};
  const out = await writeArticle({ input, generate: fakeGenerator({ wordsPerCall: 60, state }) });
  assert.ok(out.stats.retries > 0 && out.stats.continuations > 0);
  assert.ok(out.words > 700, String(out.words));
});

test('writeArticle stops immediately and keeps partial text', async () => {
  let stop = false;
  const out = await writeArticle({
    input,
    generate: fakeGenerator({ stopAt: 4 }),
    onProgress: e => { if (e.phase === 'section' && e.index === 3) stop = true; },
    shouldStop: () => stop,
  });
  assert.equal(out.stopped, true);
  assert.ok(out.headings.length >= 2 && out.headings.length < 7);
  assert.ok(!out.markdown.includes('## Kết luận'));
});

test('writeArticle surfaces runtime errors with the partial draft', async () => {
  await assert.rejects(
    writeArticle({ input, generate: fakeGenerator({ failAt: 4 }) }),
    error => error.message.includes('memory access') && error.partialMarkdown.includes('## Chọn loại xe phù hợp'),
  );
});

test('writeArticle fails honestly when the outline is unusable', async () => {
  await assert.rejects(writeArticle({ input, generate: fakeGenerator({ outline: 'ok\nno' }) }), /5 tiêu đề H2/);
});

test('English articles use an English conclusion heading', async () => {
  const en = normalizeInput({ title: 'Renting a motorbike in Hanoi', language: 'en' });
  const generate = async (messages) => {
    const p = messages.at(-1).content;
    if (/List \d+ section titles/.test(p)) return { text: 'Choosing the right bike\nDocuments to prepare\nDeposits and contracts\nInspecting the bike\nRiding safely downtown' };
    const want = Number(p.match(/about (\d+)/)?.[1] || 100);
    calls++;
    return { text: Array.from({ length: Math.ceil(want / 10) }, (_, i) => `Sentence ${calls}.${i} gives a distinct and useful riding tip.`).join(' ') };
  };
  let calls = 0;
  const out = await writeArticle({ input: en, generate });
  assert.match(out.markdown, /^## Conclusion$/m);
  assert.equal(out.headings.length, 5);
});

test('a section left empty by repeated text is dropped instead of leaving a bare H2', async () => {
  const en = normalizeInput({ title: 'Renting a motorbike in Hanoi', language: 'en' });
  const same = Array.from({ length: 20 }, (_, i) => `Sentence ${i} gives the same riding tip every single time.`).join(' ');
  const generate = async (messages) => /List \d+ section titles/.test(messages.at(-1).content)
    ? { text: 'Choosing the right bike\nDocuments to prepare\nDeposits and contracts\nInspecting the bike\nRiding safely downtown' }
    : { text: same };
  const out = await writeArticle({ input: en, generate });
  assert.ok(!/^## [^\n]+\n\n## /m.test(out.markdown), out.markdown);
  assert.ok(out.stats.retries > 0);
});

test('errors are classified for accurate messages', () => {
  assert.equal(classifyError('RangeError: WebAssembly.Memory(): could not allocate memory'), 'OUT_OF_MEMORY');
  assert.equal(classifyError('TypeError: Failed to fetch'), 'NETWORK');
  assert.equal(classifyError('Could not locate file: "https://x/onnx/model_q4.onnx".'), 'MODEL_FILES');
  assert.equal(classifyError('QuotaExceededError: Quota exceeded.'), 'STORAGE');
  assert.equal(classifyError('GPUDevice was lost'), 'DEVICE_LOST');
  assert.match(explainError(Object.assign(new Error('x'), { code: 'NO_WEBGPU' })), /WASM/);
  assert.match(explainError(new Error('Failed to fetch'), 'en'), /Network error/);
});
