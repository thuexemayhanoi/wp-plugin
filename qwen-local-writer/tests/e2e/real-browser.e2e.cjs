/* REAL end-to-end test in Chromium (CI only; needs internet to huggingface.co).
 * The real plugin screen downloads the pinned Qwen3-0.6B ONNX weights into the
 * browser, writes an article with the vendored Transformers.js/ONNX Runtime in
 * the Worker (WASM: CI runners have no GPU), and saves it as a WordPress draft.
 *
 *   WP_URL=… WP_CLI="wp --path=…" DEVICE=wasm LANGUAGE=vi node real-browser.e2e.cjs
 */
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execSync } = require('node:child_process');

const BASE = (process.env.WP_URL || 'http://localhost:8881').replace(/\/$/, '');
const OUT = process.env.E2E_OUT || path.join(__dirname, '../../../dist/qwen-real-browser');
const DEVICE = process.env.DEVICE || 'wasm';
const LANGUAGE = process.env.LANGUAGE === 'en' ? 'en' : 'vi';
const wp = cmd => execSync(`${process.env.WP_CLI} ${cmd}`, { encoding: 'utf8' }).trim();
fs.mkdirSync(OUT, { recursive: true });

const SAMPLE = LANGUAGE === 'vi'
  // No timestamp in titles: in run 39898298326 the model copied such a number into the article.
  ? { title: 'Thuê xe máy ở Long Biên: lưu ý trước khi nhận xe', keyword: 'thuê xe máy Long Biên',
      facts: 'Cho Thuê Xe Máy Nguyễn Tú, 112 Nguyễn Văn Cừ, Bồ Đề, Long Biên, Hà Nội. Điện thoại/Zalo: 0942 467 674. Mở cửa 09:00–21:00 hằng ngày. Honda Vision 200.000đ/ngày, cọc 4–5 triệu. Honda Wave 150.000đ/ngày, cọc 2–3 triệu. Giá tham khảo, cần xác nhận trước khi đặt.' }
  : { title: 'Renting a Scooter in Long Bien, Hanoi: What to Check', keyword: 'renting a scooter in Hanoi',
      facts: 'Nguyen Tu Motorbike Rental, 112 Nguyen Van Cu, Bo De, Long Bien, Hanoi. Phone/Zalo: +84 942 467 674. Open 09:00–21:00 daily. Honda Vision 200,000 VND/day, deposit 4–5 million VND. Prices are references and must be confirmed.' };

let failures = 0;
const check = (ok, message) => { if (!ok) failures++; console.log(`${ok ? 'ok' : 'FAIL'} - ${message}`); };
const minutes = ms => Math.round(ms / 600) / 100;

(async () => {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'qlw-profile-'));
  // Persistent profile: real disk quota for the ~0.6–0.9 GB model cache, like a normal browser.
  const context = await chromium.launchPersistentContext(profile, { headless: true, viewport: { width: 1366, height: 900 }, args: ['--enable-unsafe-webgpu'] });
  const hosts = new Map();
  context.on('request', req => {
    const url = new URL(req.url());
    if (url.protocol.startsWith('http') && !req.url().startsWith(BASE)) hosts.set(url.host, (hosts.get(url.host) || 0) + 1);
  });
  const page = context.pages()[0] || await context.newPage();
  const logs = [];
  page.on('console', m => logs.push(`[${m.type()}] ${m.text()}`.slice(0, 400)));
  page.on('pageerror', e => logs.push('[pageerror] ' + e.message));

  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', process.env.WP_USER || 'admin');
  await page.fill('#user_pass', process.env.WP_PASS || 'admin-pass-123');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  await page.goto(`${BASE}/wp-admin/admin.php?page=qlw-local`);
  await page.waitForSelector('#qlw-env li[data-ok]', { timeout: 60000 });
  console.log('Environment:', (await page.textContent('#qlw-env')).replace(/\s+/g, ' '));
  check(await page.evaluate(() => crossOriginIsolated), 'writer page is cross-origin isolated (multi-threaded WASM)');

  const loadStart = Date.now();
  await page.selectOption('#qlw-device', DEVICE);
  await page.click('#qlw-load');
  await page.waitForFunction(() => /sẵn sàng|Không tải được/.test(document.getElementById('qlw-model-status').textContent), null, { timeout: 15 * 60000 });
  const loadStatus = await page.textContent('#qlw-model-status');
  const loadMs = Date.now() - loadStart;
  check(/sẵn sàng/.test(loadStatus), `real model loaded in ${minutes(loadMs)} min: ${loadStatus}`);
  if (!/sẵn sàng/.test(loadStatus)) throw new Error('model did not load');

  await page.fill('#qlw-title-input', SAMPLE.title);
  await page.fill('#qlw-keyword', SAMPLE.keyword);
  await page.selectOption('#qlw-language', LANGUAGE);
  await page.fill('#qlw-facts', SAMPLE.facts);
  const genStart = Date.now();
  await page.click('#qlw-generate');
  const ticker = setInterval(async () => {
    try { console.log(`[${minutes(Date.now() - genStart)} min]`, await page.textContent('#qlw-progress'), '|', await page.textContent('#qlw-write-text')); } catch { /* page busy */ }
  }, 60000);
  await page.waitForFunction(() => /Đã viết xong|Đã dừng|Chưa hoàn tất/.test(document.getElementById('qlw-progress').textContent), null, { timeout: 75 * 60000 });
  clearInterval(ticker);
  const genMs = Date.now() - genStart;
  const progress = await page.textContent('#qlw-progress');
  const markdown = await page.inputValue('#qlw-markdown');
  const rate = await page.textContent('#qlw-write-text');
  check(/Đã viết xong/.test(progress), `real article generated in ${minutes(genMs)} min (${rate}): ${progress}`);
  fs.writeFileSync(path.join(OUT, `browser-${LANGUAGE}.md`), markdown);
  console.log('\n===== BROWSER ARTICLE\n' + markdown + '\n=====\n');

  await page.click('#qlw-save');
  await page.waitForFunction(() => /bản NHÁP #\d+|Lỗi lưu nháp/.test(document.getElementById('qlw-result').textContent), null, { timeout: 60000 });
  const result = await page.textContent('#qlw-result');
  const id = Number((result.match(/NHÁP #(\d+)/) || [])[1]);
  const score = Number((result.match(/QA kỹ thuật (\d+)\/100/) || [])[1]);
  console.log('Save result:', result);
  check(id > 0 && wp(`post get ${id} --field=post_status`) === 'draft', `saved as WordPress draft #${id}`);
  check(score >= 70, `technical QA ${score}/100 (threshold 70)`);
  const generation = JSON.parse(wp(`post meta get ${id} _qlw_generation`) || '{}');
  console.log('Generation meta:', generation);
  await page.screenshot({ path: path.join(OUT, `browser-${LANGUAGE}.png`), fullPage: true });

  // Second load must come from the browser cache, not the network.
  const before = hosts.get('huggingface.co') || 0;
  await page.reload();
  await page.waitForSelector('#qlw-env li[data-ok]', { timeout: 60000 });
  const cacheStart = Date.now();
  await page.selectOption('#qlw-device', DEVICE);
  await page.click('#qlw-load');
  await page.waitForFunction(() => /sẵn sàng|Không tải được/.test(document.getElementById('qlw-model-status').textContent), null, { timeout: 10 * 60000 });
  const cachedMs = Date.now() - cacheStart;
  const after = hosts.get('huggingface.co') || 0;
  check(/sẵn sàng/.test(await page.textContent('#qlw-model-status')), `reload uses the browser cache (${minutes(cachedMs)} min, ${after - before} new huggingface.co requests)`);

  // Hugging Face serves weights from its own CDN hosts (e.g. us.aws.cdn.hf.co); Gravatar is WordPress core's admin bar.
  const allowed = /^(?:huggingface\.co|(?:[a-z0-9-]+\.)+hf\.co|(?:[a-z0-9-]+\.)+huggingface\.co|secure\.gravatar\.com)$/;
  const unexpected = [...hosts.keys()].filter(h => !allowed.test(h));
  check(unexpected.length === 0, `only model-hub hosts were contacted: ${JSON.stringify(Object.fromEntries(hosts))}`);

  const summary = { device: DEVICE, language: LANGUAGE, loadMinutes: minutes(loadMs), generateMinutes: minutes(genMs), cachedLoadMinutes: minutes(cachedMs), rate, words: (markdown.match(/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/gu) || []).length, qa: score, postId: id, generation, hosts: Object.fromEntries(hosts) };
  fs.writeFileSync(path.join(OUT, `browser-${LANGUAGE}.json`), JSON.stringify(summary, null, 2));
  fs.writeFileSync(path.join(OUT, `browser-${LANGUAGE}.console.log`), logs.join('\n'));
  console.log('SUMMARY', JSON.stringify(summary));
  await context.close();
  process.exit(failures ? 1 : 0);
})().catch(error => { console.error(error); process.exit(1); });
