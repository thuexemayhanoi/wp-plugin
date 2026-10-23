/* Qwen Local Writer admin screen (ES module, wp-admin only).
 *
 * Model weights are downloaded into this administrator's browser and run in a
 * Worker. Text leaves the browser only when the editor clicks "QA" or "Save",
 * and then only to this WordPress site's admin-ajax.php.
 */
import {
  normalizeInput, writeArticle, countWords, explainError, TARGET_WORDS, BRIEF_PROMPT_LIMIT,
} from '@qlw/core';

const root = document.getElementById('qlw-app');

if (root) {
  const config = JSON.parse(root.dataset.config || '{}');
  const $ = id => document.getElementById('qlw-' + id);
  const ui = {
    env: $('env'), device: $('device'), load: $('load'), unload: $('unload'), clear: $('clear-cache'), insertFacts: $('insert-facts'),
    downloadWrap: $('download-wrap'), download: $('download'), downloadText: $('download-text'), modelStatus: $('model-status'),
    title: $('title-input'), keyword: $('keyword'), language: $('language'), category: $('category'), facts: $('facts'), factsCount: $('facts-count'),
    generate: $('generate'), stop: $('stop'), writeWrap: $('write-wrap'), write: $('write'), writeText: $('write-text'), progress: $('progress'),
    editor: $('markdown'), count: $('count'), qa: $('qa'), save: $('save'), newPost: $('new'), result: $('result'),
  };
  const state = { worker: null, ready: null, loaded: null, busy: false, stop: false, postId: 0, generation: null, env: null };
  const pending = new Map();
  let nextId = 0;
  let tokenListener = null;

  const fmt = n => new Intl.NumberFormat('vi-VN').format(n);
  const say = (node, text, tone = '') => { node.textContent = text; node.dataset.tone = tone; };
  const setBar = (wrap, bar, label, percent, text) => {
    wrap.hidden = false;
    bar.value = Math.max(0, Math.min(100, percent));
    label.textContent = text ?? Math.round(bar.value) + '%';
  };

  /* ---------- Local recovery (this browser only) ---------- */
  const storeKey = config.cacheKey + ':draft';
  const persist = () => {
    try {
      localStorage.setItem(storeKey, JSON.stringify({
        title: ui.title.value, keyword: ui.keyword.value, language: ui.language.value, category: ui.category?.value,
        facts: ui.facts.value, markdown: ui.editor.value, postId: state.postId, generation: state.generation, at: Date.now(),
      }));
    } catch { /* storage full or disabled: recovery is best effort */ }
  };
  const restore = () => {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(storeKey) || 'null'); } catch { saved = null; }
    if (!saved || !(saved.markdown || saved.title)) return;
    ui.title.value = saved.title || '';
    ui.keyword.value = saved.keyword || '';
    ui.language.value = saved.language === 'en' ? 'en' : 'vi';
    if (ui.category && saved.category) ui.category.value = saved.category;
    ui.facts.value = saved.facts || '';
    ui.editor.value = saved.markdown || '';
    state.postId = Number(saved.postId) || 0;
    state.generation = saved.generation || null;
    say(ui.result, 'Đã khôi phục bản đang soạn từ trình duyệt này' + (state.postId ? ` (gắn với nháp #${state.postId})` : '') + '.', 'info');
  };
  const clearRecovery = () => { try { localStorage.removeItem(storeKey); } catch { /* ignore */ } };
  let persistTimer = 0;
  const schedulePersist = () => { clearTimeout(persistTimer); persistTimer = setTimeout(persist, 400); };
  // While tokens stream in, a debounce would never fire: save at most every 3 seconds instead.
  let lastPersist = 0;
  const persistThrottled = () => {
    if (Date.now() - lastPersist < 3000) return;
    lastPersist = Date.now();
    persist();
  };

  function updateCounts() {
    const words = countWords(ui.editor.value);
    ui.count.textContent = `${fmt(words)} từ (mục tiêu ~${fmt(TARGET_WORDS)}; điểm QA tính khi bấm Chấm QA hoặc Lưu)`;
    const len = ui.facts.value.length;
    ui.factsCount.textContent = `${fmt(len)}/16.000 ký tự` + (len > BRIEF_PROMPT_LIMIT ? ` — chỉ ${fmt(BRIEF_PROMPT_LIMIT)} ký tự đầu được đưa vào prompt` : '');
    updateSaveLabel();
  }
  function updateSaveLabel() {
    ui.save.textContent = state.postId ? `Cập nhật bản nháp #${state.postId}` : 'Lưu bản nháp (không xuất bản)';
    ui.newPost.hidden = !state.postId;
  }
  for (const field of [ui.title, ui.keyword, ui.language, ui.category, ui.facts, ui.editor].filter(Boolean)) {
    field.addEventListener('input', () => { updateCounts(); schedulePersist(); });
    field.addEventListener('change', schedulePersist);
  }

  /* ---------- Worker ---------- */
  function startWorker() {
    if (state.worker) return state.ready;
    // A blob: bootstrap inherits this page's cross-origin isolation (multi-threaded WASM).
    const bootstrap = URL.createObjectURL(new Blob([`import ${JSON.stringify(new URL(config.workerUrl, location.href).href)};`], { type: 'text/javascript' }));
    let worker;
    try {
      worker = new Worker(bootstrap, { type: 'module', name: 'qwen-local-writer' });
    } catch (error) {
      return Promise.reject(Object.assign(new Error('Không tạo được Worker: ' + error.message), { code: 'UNSUPPORTED' }));
    }
    state.worker = worker;
    let started = false;
    state.ready = new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(Object.assign(new Error('Worker không khởi động sau 30 giây.'), { code: 'UNSUPPORTED' })), 30000);
      worker.addEventListener('message', ({ data }) => {
        if (data?.type === 'ready') { started = true; clearTimeout(timer); URL.revokeObjectURL(bootstrap); resolve(); }
      });
      worker.addEventListener('error', event => {
        // Handled here: without preventDefault the error is also reported as an uncaught page error.
        event.preventDefault();
        clearTimeout(timer);
        // Before "ready" the Worker could not start at all; afterwards it crashed (usually out of memory).
        const error = Object.assign(new Error('Worker lỗi: ' + (event.message || 'không tải được mã Worker (CSP/COEP?)')), { code: started ? 'WORKER_CRASHED' : 'UNSUPPORTED' });
        for (const p of pending.values()) p.reject(error);
        pending.clear();
        state.worker = null;
        state.loaded = null;
        reject(error);
      });
    });
    worker.addEventListener('message', ({ data }) => {
      if (!data) return;
      if (data.type === 'download') {
        const percent = data.total ? (data.loaded / data.total) * 100 : 0;
        setBar(ui.downloadWrap, ui.download, ui.downloadText, percent, `${Math.round(percent)}% · ${fmt(Math.round(data.loaded / 1048576))}/${fmt(Math.round(data.total / 1048576))} MB`);
        return;
      }
      if (data.type === 'status') { say(ui.modelStatus, data.message); return; }
      if (data.type === 'token') { tokenListener?.(data); return; }
      const p = pending.get(data.id);
      if (!p) return;
      pending.delete(data.id);
      if (data.ok) p.resolve(data.result);
      else p.reject(Object.assign(new Error(data.error || 'Worker thất bại.'), { code: data.code, attempts: data.attempts }));
    });
    return state.ready;
  }
  async function call(action, payload = {}) {
    await startWorker();
    return new Promise((resolve, reject) => {
      const id = ++nextId;
      pending.set(id, { resolve, reject });
      state.worker.postMessage({ id, action, payload });
    });
  }

  /* ---------- Environment check ---------- */
  async function checkEnvironment() {
    const rows = [];
    // Dashicons instead of emoji: WordPress would swap emoji for images from its CDN.
    const ok = (good, text) => rows.push(`<li data-ok="${good ? 'yes' : 'no'}"><span class="dashicons ${good ? 'dashicons-yes-alt' : 'dashicons-warning'}" aria-hidden="true"></span> ${text}</li>`);
    if (!window.isSecureContext) ok(false, 'Trang không chạy HTTPS: trình duyệt chặn WebGPU và bộ nhớ cache. Dùng HTTPS.');
    let info = null;
    try {
      info = await call('probe');
    } catch (error) {
      ok(false, 'Không khởi động được Worker: ' + escapeHtml(explainError(error)));
    }
    state.env = info;
    if (info) {
      if (info.adapter && !info.fallbackAdapter) ok(true, `WebGPU sẵn sàng (${escapeHtml(info.adapter.vendor || 'GPU')}${info.f16 ? ', hỗ trợ shader-f16 → q4f16' : ', không có shader-f16 → q4'}).`);
      else if (info.adapter) ok(false, 'WebGPU chỉ có bộ giả lập phần mềm; chế độ Tự động sẽ dùng WASM (CPU).');
      else ok(false, info.webgpu ? 'Có WebGPU nhưng không tìm thấy GPU phù hợp; sẽ dùng WASM (CPU, chậm hơn).' : 'Trình duyệt không có WebGPU; sẽ dùng WASM (CPU, chậm hơn, có thể 10–30 phút/bài).');
      ok(info.isolated, info.isolated ? `WASM đa luồng bật (${info.cores} luồng CPU).` : 'WASM chạy một luồng (thiếu cross-origin isolation). Vẫn hoạt động nhưng chậm hơn.');
    }
    const memory = navigator.deviceMemory;
    if (memory) ok(memory >= 8, `RAM báo cáo bởi trình duyệt: ~${memory} GB${memory < 8 ? ' (khuyến nghị ≥ 8 GB; có thể thiếu bộ nhớ)' : ''}.`);
    try {
      const estimate = await navigator.storage?.estimate?.();
      if (estimate?.quota) {
        const freeGb = (estimate.quota - (estimate.usage || 0)) / 1073741824;
        ok(freeGb > 1.5, `Dung lượng trình duyệt còn ~${freeGb.toFixed(1)} GB (model cần ~0,6–0,9 GB).`);
      }
    } catch { /* estimate unsupported */ }
    if (/iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
      ok(false, 'iPhone/iPad: giao diện dùng được, nhưng Safari có thể đóng tab khi model dùng nhiều bộ nhớ. Nên viết bài trên máy tính.');
    }
    ui.env.innerHTML = rows.join('');
    ui.load.disabled = !info;
  }
  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  /* ---------- Model ---------- */
  ui.load.addEventListener('click', async () => {
    ui.load.disabled = true;
    ui.generate.disabled = true;
    say(ui.modelStatus, 'Đang tải thư viện và Qwen vào trình duyệt. Không đóng tab…');
    try {
      const loaded = await call('load', {
        device: ui.device.value,
        config: { model: config.model.id, revision: config.model.revision, selfHostedBase: config.selfHostedBase || '' },
      });
      state.loaded = loaded;
      ui.downloadWrap.hidden = true;
      const fallback = loaded.attempts?.length ? ` Đã chuyển sang ${loaded.device.toUpperCase()} vì: ${loaded.attempts.map(a => a.device + ' lỗi').join(', ')}.` : '';
      say(ui.modelStatus, `Qwen đã sẵn sàng: ${loaded.device.toUpperCase()} · ${loaded.dtype} · khởi tạo ${loaded.seconds}s.${fallback}`, 'ok');
      ui.generate.disabled = false;
      ui.unload.disabled = false;
      say(ui.progress, 'Nhập tiêu đề rồi bấm "Viết nháp ~1.500 từ".');
    } catch (error) {
      say(ui.modelStatus, 'Không tải được Qwen: ' + explainError(error), 'error');
    } finally {
      ui.load.disabled = false;
    }
  });
  // Frees the model's RAM/GPU memory now; the downloaded files stay in the browser cache.
  ui.unload.addEventListener('click', async () => {
    if (state.busy) return;
    try {
      await call('unload');
    } catch { /* the Worker is terminated below anyway */ }
    state.worker?.terminate();
    state.worker = null;
    state.ready = null;
    state.loaded = null;
    ui.generate.disabled = true;
    ui.unload.disabled = true;
    say(ui.modelStatus, 'Đã giải phóng bộ nhớ của Qwen. Bấm "Tải Qwen" để dùng lại (lấy từ cache, không tải lại).', 'info');
  });
  ui.insertFacts?.addEventListener('click', () => {
    const facts = String(config.verifiedFacts || '').trim();
    if (!facts) return;
    if (ui.facts.value.includes(facts)) {
      say(ui.result, 'Brief đã có dữ kiện đã xác minh.', 'info');
      return;
    }
    ui.facts.value = (ui.facts.value.trim() ? ui.facts.value.trim() + '\n\n' : '') + facts;
    updateCounts();
    schedulePersist();
  });
  ui.clear.addEventListener('click', async () => {
    if (state.busy) return;
    if (!confirm('Xóa model Qwen đã lưu trong trình duyệt này? Lần sau phải tải lại ~0,6–0,9 GB.')) return;
    try {
      const result = await call('clear-cache');
      state.loaded = null;
      ui.generate.disabled = true;
      ui.unload.disabled = true;
      say(ui.modelStatus, result.removed ? 'Đã xóa cache model và giải phóng bộ nhớ.' : 'Không có cache model nào để xóa.', 'info');
    } catch (error) {
      say(ui.modelStatus, 'Không xóa được cache: ' + explainError(error), 'error');
    }
  });

  /* ---------- Writing ---------- */
  ui.stop.addEventListener('click', () => {
    state.stop = true;
    state.worker?.postMessage({ action: 'interrupt' });
    say(ui.progress, 'Đang dừng… phần đã viết được giữ lại.', 'info');
  });

  ui.generate.addEventListener('click', async () => {
    if (!state.loaded || state.busy) return;
    let input;
    try {
      input = normalizeInput({ title: ui.title.value, keyword: ui.keyword.value, facts: ui.facts.value, language: ui.language.value });
    } catch (error) {
      say(ui.progress, error.message, 'error');
      ui.title.focus();
      return;
    }
    if (ui.editor.value.trim() && !confirm('Thay nội dung hiện tại bằng bài mới?')) return;
    state.busy = true;
    state.stop = false;
    ui.generate.disabled = ui.load.disabled = ui.save.disabled = ui.qa.disabled = true;
    ui.stop.disabled = false;
    ui.editor.readOnly = true;
    ui.result.textContent = '';
    const started = performance.now();
    let base = '';
    let live = '';
    let rate = 0;
    tokenListener = data => {
      live += data.text;
      ui.editor.value = base + (base ? '\n\n' : '') + live;
      ui.editor.scrollTop = ui.editor.scrollHeight;
      persistThrottled();
    };
    const generate = async (messages, params) => {
      live = '';
      const result = await call('generate', { messages, params });
      rate = result.tokensPerSecond || rate;
      return result;
    };
    try {
      const output = await writeArticle({
        input,
        generate,
        shouldStop: () => state.stop,
        onProgress: event => {
          base = event.markdown.trim();
          ui.editor.value = base;
          updateCounts();
          const percent = (event.step / event.totalSteps) * 100;
          const elapsed = (performance.now() - started) / 1000;
          const eta = event.step > 0 ? Math.max(0, Math.round((elapsed / event.step) * (event.totalSteps - event.step) / 60)) : null;
          setBar(ui.writeWrap, ui.write, ui.writeText, percent, `${Math.round(percent)}%${rate ? ` · ${rate} token/s` : ''}${eta !== null && event.phase !== 'done' ? ` · còn ~${eta} phút` : ''}`);
          if (event.message) say(ui.progress, event.message);
          schedulePersist();
        },
      });
      ui.editor.value = output.markdown;
      state.generation = {
        device: state.loaded.device, dtype: state.loaded.dtype, model: config.model.id, revision: config.model.revision,
        tokens: output.stats.tokens, seconds: Math.round((performance.now() - started) / 1000), calls: output.stats.calls,
        retries: output.stats.retries, stopped: output.stopped, version: config.version,
      };
      say(ui.progress, (output.stopped ? 'Đã dừng theo yêu cầu. ' : 'Đã viết xong bản thảo. ') +
        `${fmt(output.words)} từ, ${output.headings.length} H2, ${Math.round((performance.now() - started) / 60000)} phút. Đọc lại, sửa dữ liệu rồi lưu nháp.`, output.stopped ? 'info' : 'ok');
    } catch (error) {
      if (error.partialMarkdown) ui.editor.value = error.partialMarkdown;
      say(ui.progress, 'Chưa hoàn tất: ' + explainError(error) + ' Nội dung đã viết được giữ nguyên.', 'error');
      if (error.code === 'DEVICE_LOST' || error.code === 'UNSUPPORTED' || error.code === 'WORKER_CRASHED') {
        state.loaded = null;
        state.worker?.terminate();
        state.worker = null;
      }
    } finally {
      tokenListener = null;
      state.busy = false;
      ui.editor.readOnly = false;
      ui.generate.disabled = !state.loaded;
      ui.unload.disabled = !state.loaded;
      ui.load.disabled = false;
      ui.save.disabled = ui.qa.disabled = false;
      ui.stop.disabled = true;
      updateCounts();
      persist();
    }
  });

  /* ---------- QA & save ---------- */
  function formData(extra = {}) {
    const body = new URLSearchParams({
      nonce: config.nonce,
      title: ui.title.value.trim(),
      keyword: ui.keyword.value.trim(),
      language: ui.language.value,
      category: ui.category ? ui.category.value : '0',
      brief: ui.facts.value,
      markdown: ui.editor.value,
      ...extra,
    });
    return body;
  }
  async function post(action, extra) {
    const body = formData(extra);
    body.set('action', action);
    let response;
    try {
      response = await fetch(config.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
      });
    } catch (error) {
      throw Object.assign(new Error('Mất kết nối tới WordPress. Nội dung vẫn được giữ trong trình duyệt; thử lại sau.'), { code: 'NETWORK' });
    }
    let json = null;
    try { json = await response.json(); } catch { json = null; }
    if (!json) throw new Error(`WordPress trả về phản hồi không hợp lệ (HTTP ${response.status}).`);
    if (!json.success) throw Object.assign(new Error(json.data?.message || `Lỗi HTTP ${response.status}.`), { data: json.data || {}, status: response.status });
    return json.data;
  }
  const GROUPS = [
    ['technical', 'QA kỹ thuật'],
    ['factual', 'Kiểm chứng dữ kiện'],
    ['seo', 'SEO'],
  ];
  function renderQa(qa, prefix) {
    const wrap = document.createElement('div');
    const head = document.createElement('p');
    head.className = 'qlw-qa-score';
    head.dataset.pass = qa.ready_for_review ? 'yes' : 'no';
    head.textContent = `${prefix}Điểm QA tự động ${qa.score}/100 · ${fmt(qa.word_count)} từ · ${qa.h2_count} H2 — ` +
      (qa.ready_for_review ? 'Đạt ngưỡng 70: đủ điều kiện để biên tập viên rà soát.' : 'Chưa đạt 70: cần sửa trước khi đăng.');
    wrap.append(head);
    const checks = qa.checks || [];
    for (const [group, label] of GROUPS) {
      const items = checks.filter(item => (item.group || 'technical') === group);
      if (!items.length) continue;
      const summary = qa.groups?.[group];
      const title = document.createElement('h3');
      title.className = 'qlw-qa-group';
      title.dataset.group = group;
      title.textContent = summary ? `${label} — ${summary.failed ? `${summary.failed} mục cần xem, trừ ${summary.penalty} điểm` : 'không có cảnh báo'}` : label;
      const list = document.createElement('ul');
      list.className = 'qlw-checks';
      for (const item of items) {
        const li = document.createElement('li');
        li.dataset.pass = item.pass ? 'yes' : 'no';
        li.textContent = (item.pass ? 'Đạt: ' : item.penalty ? `Trừ ${item.penalty}: ` : 'Lưu ý: ') + item.message;
        list.append(li);
      }
      wrap.append(title, list);
    }
    if (qa.fact_check?.length) {
      const title = document.createElement('h3');
      title.className = 'qlw-qa-group';
      title.textContent = 'Câu cần kiểm chứng trước khi đăng';
      const list = document.createElement('ul');
      list.className = 'qlw-facts-list';
      for (const item of qa.fact_check) {
        const li = document.createElement('li');
        const reason = document.createElement('strong');
        reason.textContent = item.reason + ' ';
        const text = document.createElement('q');
        text.textContent = item.text;
        li.append(reason, text);
        list.append(li);
      }
      wrap.append(title, list);
    }
    const note = document.createElement('p');
    note.className = 'qlw-muted';
    note.textContent = (qa.fact_status === 'flagged' ? 'Có chi tiết cần kiểm chứng. ' : 'Không thấy dấu hiệu sai đã biết — điều này KHÔNG có nghĩa là bài đúng. ') +
      'Biên tập (con người): chưa xác nhận — mở bài nháp, kiểm chứng giá, địa chỉ, chính sách rồi tích ô "Biên tập" trong hộp Qwen Local Writer.';
    wrap.append(note);
    return wrap;
  }
  ui.qa.addEventListener('click', async () => {
    ui.qa.disabled = true;
    try {
      const data = await post('qlw_qa_preview', { post_id: String(state.postId || 0) });
      ui.result.replaceChildren(renderQa(data.qa, ''));
    } catch (error) {
      say(ui.result, 'Không chấm được QA: ' + error.message, 'error');
    } finally {
      ui.qa.disabled = false;
    }
  });
  async function save(allowDuplicate = false) {
    ui.save.disabled = true;
    try {
      if (ui.title.value.trim().length < 5) throw new Error('Nhập tiêu đề tối thiểu 5 ký tự.');
      if (ui.editor.value.trim().length < 120) throw new Error('Bản thảo chưa đủ nội dung (tối thiểu 120 ký tự).');
      const data = await post('qlw_save_draft', {
        post_id: String(state.postId || 0),
        allow_duplicate: allowDuplicate ? '1' : '',
        generation: JSON.stringify(state.generation || {}),
      });
      state.postId = data.post_id;
      persist();
      const box = renderQa(data.qa, `${data.updated ? 'Đã cập nhật' : 'Đã lưu'} bản NHÁP #${data.post_id}. `);
      if (data.edit_url) {
        const link = document.createElement('a');
        link.href = data.edit_url;
        link.className = 'button';
        link.textContent = 'Mở bài nháp để biên tập';
        box.append(link);
      }
      ui.result.replaceChildren(box);
      ui.result.dataset.tone = 'ok';
    } catch (error) {
      if (error.data?.code === 'duplicate') {
        const list = error.data.duplicates.map(d => `#${d.id} (${d.status})`).join(', ');
        if (confirm(`Đã có bài cùng tiêu đề: ${list}.\nVẫn lưu thêm một bản nháp mới?`)) {
          ui.save.disabled = false;
          return save(true);
        }
        say(ui.result, `Chưa lưu: đã có bài cùng tiêu đề (${list}). Đổi tiêu đề để tránh trùng bài.`, 'error');
      } else {
        say(ui.result, 'Lỗi lưu nháp: ' + error.message, 'error');
      }
    } finally {
      ui.save.disabled = false;
      updateSaveLabel();
    }
  }
  ui.save.addEventListener('click', () => save(false));
  ui.newPost.addEventListener('click', () => {
    if (!confirm('Bắt đầu bài mới? Nội dung trong khung soạn sẽ bị xóa (bản nháp đã lưu trong WordPress vẫn giữ nguyên).')) return;
    state.postId = 0;
    state.generation = null;
    ui.editor.value = '';
    ui.title.value = '';
    ui.keyword.value = '';
    ui.result.textContent = '';
    clearRecovery();
    updateCounts();
  });

  window.addEventListener('beforeunload', event => {
    persist();
    if (state.busy) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  restore();
  updateCounts();
  if (typeof Worker === 'undefined' || typeof WebAssembly !== 'object') {
    ui.env.innerHTML = '<li data-ok="no"><span class="dashicons dashicons-warning" aria-hidden="true"></span> Trình duyệt này không hỗ trợ Web Worker/WebAssembly. Dùng Chrome, Edge, Firefox hoặc Safari bản mới.</li>';
  } else {
    checkEnvironment();
  }
}
