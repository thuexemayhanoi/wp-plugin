(() => {
  'use strict';
  const api = async (path, body) => {
    const r = await fetch(MASFAdmin.url + path, {
      method: body ? 'POST' : 'GET', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': MASFAdmin.nonce, 'Content-Type': 'application/json' },
      ...(body ? { body: JSON.stringify(body) } : {})
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.message || 'Không hoàn tất yêu cầu.');
    return data;
  };
  document.querySelectorAll('.masf-api').forEach(button => {
    button.addEventListener('click', async () => {
      const panel = button.closest('.masf-panel');
      const result = panel.querySelector('.masf-result');
      button.disabled = true; result.textContent = 'Đang kiểm tra…';
      try {
        const data = await api('provider', { provider: button.dataset.provider, operation: button.dataset.operation });
        result.textContent = data.message;
        if (data.models) {
          const list = panel.querySelector('datalist');
          list.replaceChildren(...data.models.map(id => { const option = document.createElement('option'); option.value = id; return option; }));
        }
      } catch (error) { result.textContent = error.message; }
      finally { button.disabled = false; }
    });
  });
  const quick = document.querySelector('.masf-quick');
  if (quick) {
    const provider = quick.querySelector('select[name="provider"]');
    const key = quick.querySelector('input[name="api_key"]');
    const model = quick.querySelector('#masf-quick-model');
    const tokens = quick.querySelector('select[name="output_tokens"]');
    const toggle = quick.querySelector('#masf-show-key');
    const configurations = MASFAdmin.providers || {};
    provider.addEventListener('change', () => {
      const data = configurations[provider.value] || {};
      model.value = data.model || '';
      tokens.value = ['4000', '8000', '16000', '32000', '64000'].includes(String(data.tokens)) ? String(data.tokens) : '16000';
      quick.querySelectorAll('[data-key-provider]').forEach(n => n.classList.toggle('masf-hidden', n.dataset.keyProvider !== provider.value));
    });
    toggle.addEventListener('change', () => { key.type = toggle.checked ? 'text' : 'password'; });
    quick.querySelector('form').addEventListener('submit', event => {
      const count = Number(quick.querySelector('input[name="count"]').value);
      const text = quick.querySelector('textarea[name="titles"]').value.trim();
      const file = quick.querySelector('input[name="matrix_file"]').files;
      if (!text && !file.length) { event.preventDefault(); alert('Nhập tiêu đề hoặc chọn Excel/CSV.'); return; }
      if (count > 1 && Number(tokens.value) === 4000) {
        // Not a blocker; 4K may be too short for the requested word count.
        if (!window.confirm('4.000 output tokens có thể không đủ cho bài dài, dẫn đến lỗi QA. Vẫn tiếp tục?')) event.preventDefault();
      }
    });
  }
  if (document.getElementById('masf-summary')) {
    const update = async () => {
      if (document.hidden) return;
      try {
        const data = await api('status');
        Object.entries(data.counts).forEach(([status, n]) => {
          const node = document.querySelector('[data-status="' + status + '"]');
          if (node) node.textContent = String(n);
        });
        document.getElementById('masf-progress').textContent = data.progress + '%';
        document.querySelector('.masf progress').value = data.progress;
      } catch (_) { /* Session/network expiry does not control background work. */ }
    };
    setInterval(update, 15000);
  }
})();
