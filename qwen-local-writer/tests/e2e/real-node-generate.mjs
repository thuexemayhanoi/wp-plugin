/* REAL inference test (needs internet to huggingface.co; runs in CI, never in the plugin).
 * Drives the actual Qwen3-0.6B ONNX weights at the pinned revision through the
 * plugin's own orchestration (assets/qlw-core.js) on CPU via onnxruntime-node,
 * then scores every article with the plugin's PHP QA.
 *
 *   npm i @huggingface/transformers@4.3.1 && node real-node-generate.mjs <out-dir>
 */
import { pipeline, TextStreamer } from '@huggingface/transformers';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { MODEL, writeArticle, normalizeInput, generationOptions } from '../../assets/qlw-core.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(process.argv[2] || 'dist/qwen-real');
mkdirSync(out, { recursive: true });

const NAP_VI = `Cho Thuê Xe Máy Nguyễn Tú. Địa chỉ: 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội. Điện thoại/Zalo: 0942 467 674. Giờ hoạt động: 09:00–21:00 tất cả các ngày.
Giá tham khảo (cần xác nhận trước khi đặt): Honda Wave 150.000đ/ngày, 700.000đ/tuần, cọc 2–3 triệu. Honda Vision 200.000đ/ngày, 1.000.000đ/tuần, cọc 4–5 triệu. Honda Air Blade 200.000đ/ngày, cọc 3 triệu. Xe tay ga 50cc 200.000đ/ngày, cọc 4 triệu. Xe điện: liên hệ.
Tiền cọc từ 2.000.000đ đến 5.000.000đ tùy xe, hoàn trả theo hợp đồng sau khi bàn giao xe. Không quảng cáo thuê xe không cần đặt cọc.`;
const NAP_EN = `Nguyen Tu Motorbike Rental. Address: 112 Nguyen Van Cu, Bo De, Long Bien, Hanoi. Phone/Zalo: +84 942 467 674. Open 09:00–21:00 every day.
Reference prices (confirm before booking): Honda Wave 150,000 VND/day, deposit 2–3 million VND. Honda Vision 200,000 VND/day, deposit 4–5 million VND. Honda Air Blade 200,000 VND/day, deposit 3 million VND. 50cc scooter 200,000 VND/day, deposit 4 million VND. Electric bikes: contact the shop.
A deposit of 2,000,000–5,000,000 VND applies depending on the bike and is refunded under the contract after the bike is returned.`;

const samples = [
  { slug: 'vi-brief-q4f16', dtype: 'q4f16', title: 'Kinh nghiệm thuê xe máy ở Hà Nội cho người mới', keyword: 'thuê xe máy ở Hà Nội', facts: NAP_VI, language: 'vi' },
  { slug: 'en-brief-q4f16', dtype: 'q4f16', title: 'How to Rent a Motorbike in Hanoi: A First-Timer Guide', keyword: 'rent a motorbike in Hanoi', facts: NAP_EN, language: 'en' },
  { slug: 'vi-general-q4', dtype: 'q4', title: 'Cách bảo quản xe tay ga trong mùa mưa ở Hà Nội', keyword: 'bảo quản xe tay ga', facts: '', language: 'vi' },
  { slug: 'en-general-q4', dtype: 'q4', title: 'Riding a Scooter Safely in Hanoi Traffic', keyword: 'riding a scooter in Hanoi', facts: '', language: 'en' },
].filter(s => !process.env.ONLY || process.env.ONLY.split(',').includes(s.slug));

const report = { model: MODEL, runtime: 'onnxruntime-node (CPU) via @huggingface/transformers 4.3.1', samples: [] };
const generators = {};
let failed = 0;

for (const sample of samples) {
  const started = Date.now();
  if (!generators[sample.dtype]) {
    const t = Date.now();
    generators[sample.dtype] = await pipeline('text-generation', MODEL.id, { dtype: sample.dtype, device: 'cpu', revision: MODEL.revision });
    console.log(`Loaded ${MODEL.id}@${MODEL.revision} ${sample.dtype} in ${(Date.now() - t) / 1000}s`);
  }
  const generator = generators[sample.dtype];
  const generate = async (messages, params) => {
    let tokens = 0;
    const streamer = new TextStreamer(generator.tokenizer, { skip_prompt: true, callback_function: () => {}, token_callback_function: () => { tokens++; } });
    const t = Date.now();
    const result = await generator(messages, { ...generationOptions(params), streamer });
    const seconds = (Date.now() - t) / 1000;
    return { text: result[0].generated_text.at(-1).content, tokens, seconds, tokensPerSecond: tokens / seconds };
  };
  const input = normalizeInput(sample);
  let entry;
  try {
    const article = await writeArticle({ input, generate, onProgress: e => e.message && console.log(`[${sample.slug}] ${e.message}`) });
    const file = path.join(out, sample.slug + '.md');
    writeFileSync(file, `<!-- ${sample.title} -->\n` + article.markdown);
    const markdownOnly = path.join(out, sample.slug + '.body.md');
    writeFileSync(markdownOnly, article.markdown);
    const briefFile = path.join(out, sample.slug + '.brief.txt');
    writeFileSync(briefFile, sample.facts);
    const qa = JSON.parse(execFileSync('php', [path.join(here, '../qa-cli.php'), markdownOnly, sample.language, briefFile, sample.keyword], { encoding: 'utf8' }));
    entry = {
      slug: sample.slug, title: sample.title, language: sample.language, dtype: sample.dtype,
      words: article.words, h2: qa.h2_count, qa: qa.score, ready: qa.ready_for_review,
      failed_checks: qa.checks.filter(c => !c.pass).map(c => `${c.id}: ${c.message}`),
      calls: article.stats.calls, retries: article.stats.retries, continuations: article.stats.continuations,
      tokens: article.stats.tokens, seconds: Math.round((Date.now() - started) / 1000), warnings: article.stats.warnings,
    };
    console.log(`\n===== ${sample.slug}: ${entry.words} words, ${entry.h2} H2, QA ${entry.qa}/100, ${entry.seconds}s, ${entry.tokens} tokens\n`);
    console.log(article.markdown);
    if (!(entry.words >= 1200 && entry.words <= 2100 && entry.ready)) failed++;
  } catch (error) {
    failed++;
    entry = { slug: sample.slug, error: String(error?.stack || error) };
    console.error(error);
  }
  report.samples.push(entry);
}

writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
console.log('\n===== SUMMARY');
console.table(report.samples.map(({ slug, words, h2, qa, ready, seconds, retries, continuations, error }) => ({ slug, words, h2, qa, ready, seconds, retries, continuations, error: error ? 'ERROR' : '' })));
for (const s of report.samples) if (s.failed_checks?.length) console.log(s.slug, '→', s.failed_checks.join(' | '));
process.exit(failed ? 1 : 0);
