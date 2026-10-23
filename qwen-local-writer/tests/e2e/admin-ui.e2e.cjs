/* Browser E2E of the real admin screen, Worker, orchestration and AJAX save on a
 * disposable WordPress. The model is replaced by tests/e2e/fake-transformers.mjs,
 * so this verifies the plugin plumbing, NOT Qwen's writing quality.
 *
 *   WP_URL=http://localhost:8881 WP_CLI="php wp-cli.phar --path=..." node admin-ui.e2e.cjs
 * Users: admin/admin-pass-123, contrib1/contrib-pass-123, sub1/sub-pass-123 (see CI workflow).
 */
const { chromium, devices } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const { execSync } = require('node:child_process');

const BASE = (process.env.WP_URL || 'http://localhost:8881').replace(/\/$/, '');
const OUT = process.env.E2E_OUT || path.join(__dirname, '../../../dist/e2e');
const FAKE = fs.readFileSync(path.join(__dirname, 'fake-transformers.mjs'), 'utf8');
const wp = cmd => execSync(`${process.env.WP_CLI} ${cmd}`, { encoding: 'utf8' }).trim();
fs.mkdirSync(OUT, { recursive: true });

let failures = 0;
let checks = 0;
const check = (ok, message) => {
  checks++;
  if (!ok) { failures++; console.error('FAIL:', message); } else { console.log('ok -', message); }
};

async function newContext(browser, { fake = {}, device = null } = {}) {
  const context = await browser.newContext(device ? { ...devices[device] } : { viewport: { width: 1366, height: 900 } });
  await context.route('**/assets/vendor/transformers/transformers.min.js*', route =>
    route.fulfill({ contentType: 'text/javascript', body: `const FAKE = ${JSON.stringify(fake)};\n${FAKE}` }));
  const external = [];
  context.on('request', req => {
    const url = req.url();
    if (!url.startsWith(BASE) && !url.startsWith('blob:') && !url.startsWith('data:')) external.push(url);
  });
  context.external = external;
  return context;
}

async function login(page, user, pass) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

async function openWriter(page) {
  const response = await page.goto(`${BASE}/wp-admin/admin.php?page=qlw-local`);
  await page.waitForSelector('#qlw-env li[data-ok]', { timeout: 30000 });
  return response;
}

async function loadModel(page, device = 'auto') {
  await page.selectOption('#qlw-device', device);
  await page.click('#qlw-load');
  await page.waitForFunction(() => /sẵn sàng|Không tải được/.test(document.getElementById('qlw-model-status').textContent), null, { timeout: 30000 });
  return page.textContent('#qlw-model-status');
}

async function fillInput(page, title) {
  await page.fill('#qlw-title-input', title);
  await page.fill('#qlw-keyword', 'thuê xe máy');
  await page.fill('#qlw-facts', 'Cho Thuê Xe Máy Nguyễn Tú, 112 Nguyễn Văn Cừ, Long Biên. Honda Vision 200.000đ/ngày, cọc 4–5 triệu. Zalo 0942 467 674.');
}

(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--enable-unsafe-webgpu'] });
  const stamp = Date.now();
  const title = `Kinh nghiệm thuê xe máy ở Hà Nội E2E ${stamp}`;

  // ---- Administrator, desktop: full happy path ----
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await login(page, 'admin', 'admin-pass-123');
    const response = await openWriter(page);
    const headers = response.headers();
    check(headers['cross-origin-opener-policy'] === 'same-origin' && headers['cross-origin-embedder-policy'] === 'credentialless', 'COOP/COEP headers on the writer screen only');
    check(await page.evaluate(() => crossOriginIsolated), 'page is cross-origin isolated');
    check(await page.isHidden('#qlw-download-wrap') && await page.isHidden('#qlw-write-wrap'), 'idle progress bars are hidden');
    check(await page.locator('#qlw-env img.emoji').count() === 0, 'status list uses Dashicons, not emoji images');
    const env = await page.textContent('#qlw-env');
    check(/WASM đa luồng bật/.test(env), 'environment check reports multi-threaded WASM: ' + env.replace(/\s+/g, ' ').slice(0, 300));
    check(/giả lập phần mềm|không có WebGPU|không tìm thấy GPU/.test(env), 'environment check is honest about the missing/software GPU');
    const status = await loadModel(page, 'auto');
    check(/sẵn sàng: WASM · q4/.test(status), 'auto mode skips the software GPU and loads WASM: ' + status);

    await fillInput(page, title);
    await page.click('#qlw-generate');
    await page.waitForFunction(() => /Đã viết xong|Chưa hoàn tất/.test(document.getElementById('qlw-progress').textContent), null, { timeout: 120000 });
    const progress = await page.textContent('#qlw-progress');
    check(/Đã viết xong/.test(progress), 'generation finished: ' + progress);
    const markdown = await page.inputValue('#qlw-markdown');
    const h2 = (markdown.match(/^## /gm) || []).length;
    const words = (markdown.match(/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/gu) || []).length;
    check(h2 === 8 && /^## Kết luận$/m.test(markdown), `7 H2 sections + conclusion (${h2})`);
    check(words >= 1350 && words <= 1800, `~1,500 words assembled (${words})`);
    check(!/^# |<think>|Dưới đây là/m.test(markdown), 'no H1, <think> or preamble in the draft');

    await page.click('#qlw-qa');
    await page.waitForSelector('#qlw-result .qlw-qa-score');
    check(/QA kỹ thuật \d+\/100/.test(await page.textContent('#qlw-result')), 'QA preview shows a score');

    await page.click('#qlw-save');
    await page.waitForFunction(() => /bản NHÁP #\d+|Lỗi lưu nháp/.test(document.getElementById('qlw-result').textContent), null, { timeout: 30000 });
    const saved = await page.textContent('#qlw-result');
    const id = Number((saved.match(/NHÁP #(\d+)/) || [])[1]);
    check(id > 0, 'draft saved: ' + saved.slice(0, 200));
    check(wp(`post get ${id} --field=post_status`) === 'draft', 'WordPress post status is draft');
    check(wp(`post get ${id} --field=post_content`).includes('<!-- wp:heading -->'), 'post content is block markup');
    check(Number(wp(`post meta get ${id} _qlw_qa_score`)) >= 0, 'QA score stored in post meta');
    check(/Cập nhật bản nháp #/.test(await page.textContent('#qlw-save')), 'save button switches to update mode');

    await page.click('#qlw-save');
    await page.waitForFunction(() => /Đã cập nhật bản NHÁP/.test(document.getElementById('qlw-result').textContent), null, { timeout: 30000 });
    check(wp(`post list --post_type=post --title="${title}" --post_status=any --format=count`) === '1', 'second save updated the same draft (no duplicate)');

    // Recovery after reload.
    await page.reload();
    await page.waitForSelector('#qlw-env li[data-ok]');
    check((await page.inputValue('#qlw-markdown')).length > 1000 && /khôi phục/.test(await page.textContent('#qlw-result')), 'draft restored from browser recovery after reload');

    // Duplicate guard via UI.
    page.once('dialog', d => d.accept()); // "Bắt đầu bài mới?"
    await page.click('#qlw-new');
    await page.fill('#qlw-title-input', title);
    await page.fill('#qlw-markdown', markdown);
    page.once('dialog', d => { check(/cùng tiêu đề/.test(d.message()), 'duplicate title prompts for confirmation'); d.dismiss(); });
    await page.click('#qlw-save');
    await page.waitForFunction(() => /Chưa lưu: đã có bài cùng tiêu đề/.test(document.getElementById('qlw-result').textContent), null, { timeout: 30000 });
    check(true, 'declining the duplicate prompt does not save');

    // Mobile-friendly layout of the same screen is checked below; screenshot desktop now.
    await page.screenshot({ path: path.join(OUT, 'desktop.png'), fullPage: true });
    // WordPress core itself loads Gravatar avatars in the admin bar; anything else would be ours.
    const foreign = context.external.filter(url => !/^https:\/\/secure\.gravatar\.com\/avatar\//.test(url));
    check(foreign.length === 0, 'no plugin request left this WordPress site (no CDN, no API): ' + foreign.slice(0, 3).join(', '));
    check(errors.length === 0, 'no uncaught page errors: ' + errors.join(' | '));

    // Frontend never loads the writer.
    const front = await page.goto(`${BASE}/`);
    const html = await front.text();
    check(!/qwen-local-writer\/assets|id="qlw-app"|qwen-worker|transformers\.min\.js/.test(html), 'public site does not load any Qwen asset');
    check(!front.headers()['cross-origin-embedder-policy'], 'public site gets no COEP header');
    const editorPage = await page.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`);
    check(!editorPage.headers()['cross-origin-embedder-policy'], 'post editor gets no COEP header');
    await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0, null, { timeout: 60000 });
    const validity = await page.evaluate(() => {
      const blocks = wp.data.select('core/block-editor').getBlocks();
      const flat = [];
      const walk = list => list.forEach(b => { flat.push(b); walk(b.innerBlocks); });
      walk(blocks);
      return { total: flat.length, invalid: flat.filter(b => !b.isValid).map(b => b.name), names: [...new Set(flat.map(b => b.name))] };
    });
    check(validity.total > 10 && validity.invalid.length === 0, `block editor validates every block (${validity.total} blocks, invalid: ${validity.invalid.join(',') || 'none'}; ${validity.names.join(',')})`);
    check(await page.locator('#qlw-source').count() === 1, 'metabox with Markdown & QA is shown in the post editor');
    await context.close();
  }

  // ---- Stop, explicit WebGPU failure, network failure ----
  {
    const context = await newContext(browser, { fake: { tokenDelay: 20 } });
    const page = await context.newPage();
    await login(page, 'admin', 'admin-pass-123');
    await openWriter(page);
    await loadModel(page, 'wasm');
    await fillInput(page, `Bài dừng giữa chừng ${stamp}`);
    page.once('dialog', d => d.accept());
    await page.click('#qlw-generate');
    await page.waitForFunction(() => /H2 2\//.test(document.getElementById('qlw-progress').textContent), null, { timeout: 60000 });
    await page.click('#qlw-stop');
    await page.waitForFunction(() => /Đã dừng theo yêu cầu/.test(document.getElementById('qlw-progress').textContent), null, { timeout: 30000 });
    const partial = await page.inputValue('#qlw-markdown');
    check(partial.includes('## ') && !partial.includes('## Kết luận'), 'stop keeps the partial draft without inventing a conclusion');
    await context.close();
  }
  {
    const context = await newContext(browser, { fake: { failDevices: ['webgpu'] } });
    const page = await context.newPage();
    await login(page, 'admin', 'admin-pass-123');
    await openWriter(page);
    const status = await loadModel(page, 'webgpu');
    check(/Không tải được Qwen: .*webgpu\/q4/.test(status), 'explicit WebGPU failure is reported, not faked: ' + status);
    check(await page.isDisabled('#qlw-generate'), 'writing stays disabled after a failed load');
    await context.close();
  }
  {
    const context = await newContext(browser, { fake: { networkError: true } });
    const page = await context.newPage();
    await login(page, 'admin', 'admin-pass-123');
    await openWriter(page);
    const status = await loadModel(page, 'auto');
    check(/Mất kết nối/.test(status), 'network failure while downloading is explained: ' + status);
    await context.close();
  }
  {
    const context = await newContext(browser);
    await context.route('**/assets/qwen-worker.js*', route => route.fulfill({ status: 500, body: 'boom' }));
    const page = await context.newPage();
    await login(page, 'admin', 'admin-pass-123');
    await openWriter(page);
    check(/Không khởi động được Worker/.test(await page.textContent('#qlw-env')), 'broken Worker is reported in the environment check');
    check(await page.isDisabled('#qlw-load'), 'model loading is disabled when the Worker cannot start');
    await context.close();
  }

  // ---- Phone and tablet layouts ----
  for (const [device, file] of [['iPhone 13', 'iphone.png'], ['iPad (gen 7)', 'ipad.png']]) {
    const context = await newContext(browser, { device });
    const page = await context.newPage();
    await login(page, 'admin', 'admin-pass-123');
    await openWriter(page);
    const layout = await page.evaluate(() => {
      const card = document.querySelector('.qlw-card').getBoundingClientRect();
      const load = document.getElementById('qlw-load').getBoundingClientRect();
      const font = parseFloat(getComputedStyle(document.getElementById('qlw-title-input')).fontSize);
      return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, cardRight: card.right, width: innerWidth, buttonHeight: load.height, font };
    });
    check(layout.overflow <= 1 && layout.cardRight <= layout.width + 1, `${device}: no horizontal overflow (${JSON.stringify(layout)})`);
    check(layout.buttonHeight >= 36 && layout.font >= 16, `${device}: touch-size buttons and 16px inputs (no iOS zoom)`);
    await page.screenshot({ path: path.join(OUT, file), fullPage: true });
    await context.close();
  }

  // ---- Roles ----
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    await login(page, 'contrib1', 'contrib-pass-123');
    await openWriter(page);
    check(await page.locator('.qlw-settings').count() === 0, 'contributor does not see administrator settings');
    await loadModel(page, 'wasm');
    await fillInput(page, `Bài cộng tác viên ${stamp}`);
    await page.click('#qlw-generate');
    await page.waitForFunction(() => /Đã viết xong/.test(document.getElementById('qlw-progress').textContent), null, { timeout: 120000 });
    await page.click('#qlw-save');
    await page.waitForFunction(() => /bản NHÁP #\d+/.test(document.getElementById('qlw-result').textContent), null, { timeout: 30000 });
    const id = Number(((await page.textContent('#qlw-result')).match(/NHÁP #(\d+)/) || [])[1]);
    check(wp(`post get ${id} --field=post_status`) === 'draft', 'contributor saves a draft (cannot publish)');
    await context.close();
  }
  {
    const context = await newContext(browser);
    const page = await context.newPage();
    await login(page, 'sub1', 'sub-pass-123');
    const response = await page.goto(`${BASE}/wp-admin/admin.php?page=qlw-local`);
    check(response.status() === 403 || /not allowed|không có quyền|Sorry/i.test(await page.content()), 'subscriber cannot open the writer');
    const ajax = await page.evaluate(async base => {
      const r = await fetch(base + '/wp-admin/admin-ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'qlw_save_draft', title: 'Hack attempt title', markdown: 'x'.repeat(200) }) });
      return { status: r.status, body: await r.text() };
    }, BASE);
    check(ajax.status === 403 && /forbidden/.test(ajax.body), 'subscriber AJAX save is refused (403)');
    await context.close();
  }

  await browser.close();
  console.log(`\nAdmin UI E2E (fake model): ${checks - failures}/${checks} checks passed; screenshots in ${OUT}`);
  process.exit(failures ? 1 : 0);
})().catch(error => { console.error(error); process.exit(1); });
