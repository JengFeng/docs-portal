(() => {
  const allowed = ['small', 'medium', 'large'];
  const storageKey = 'twwater-font-size';
  const controls = Array.from(document.querySelectorAll('[data-font-size-control]'));
  const apply = (requested, persist = true) => {
    const size = allowed.includes(requested) ? requested : 'large';
    document.documentElement.dataset.fontSize = size;
    controls.forEach((control) => control.querySelectorAll('[data-font-size]').forEach((button) => button.setAttribute('aria-pressed', button.dataset.fontSize === size ? 'true' : 'false')));
    if (persist) { try { window.localStorage.setItem(storageKey, size); } catch (_) {} }
  };
  let saved = 'large';
  try { saved = window.localStorage.getItem(storageKey) || 'large'; } catch (_) {}
  apply(saved, false);
  controls.forEach((control) => control.addEventListener('click', (event) => {
    const button = event.target.closest('[data-font-size]');
    if (button instanceof HTMLButtonElement) apply(button.dataset.fontSize || 'large');
  }));
})();

document.addEventListener('change', (event) => {
  const select = event.target;
  if (!(select instanceof HTMLSelectElement) || select.name !== 'sort') return;
  const form = select.form;
  if (!(form instanceof HTMLFormElement) || !form.matches('[data-library-sort-menu]')) return;
  const panel = select.closest('details');
  if (panel instanceof HTMLDetailsElement) panel.open = false;
  form.requestSubmit();
});

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (form.matches('[data-staging-upload-form]')) return;
  for (const modeControl of form.querySelectorAll('[data-batch-mode]')) {
    if (!(modeControl instanceof HTMLSelectElement) || modeControl.value === 'unchanged') continue;
    const targetId = modeControl.dataset.target;
    const target = targetId ? document.getElementById(targetId) : null;
    if (!(target instanceof HTMLFieldSetElement) || target.querySelector('input[type="checkbox"]:checked')) continue;
    event.preventDefault();
    const panel = modeControl.closest('details');
    if (panel instanceof HTMLDetailsElement) panel.open = true;
    const propertyName = modeControl.name === 'phase_mode' ? 'SSDLC 階段角色' : '文件種類';
    window.alert(`請先勾選至少一個要套用的${propertyName}。`);
    modeControl.focus();
    return;
  }
  const message = form.dataset.confirm;
  if (message && !window.confirm(message)) {
    event.preventDefault();
    return;
  }
  const submitter = event.submitter;
  if (submitter instanceof HTMLButtonElement) {
    submitter.disabled = true;
    submitter.setAttribute('aria-busy', 'true');
    submitter.dataset.originalLabel = submitter.textContent || '';
    submitter.textContent = '處理中…';
  }
});

(() => {
  const selectAll = document.querySelector('[data-select-all-documents]');
  const documentCheckboxes = Array.from(document.querySelectorAll('[data-document-checkbox]'));
  const selectedCount = document.querySelector('[data-selected-document-count]');
  if (!(selectAll instanceof HTMLInputElement) || documentCheckboxes.length === 0) return;

  const refreshSelection = () => {
    const checked = documentCheckboxes.filter((checkbox) => checkbox instanceof HTMLInputElement && checkbox.checked).length;
    selectAll.checked = checked === documentCheckboxes.length;
    selectAll.indeterminate = checked > 0 && checked < documentCheckboxes.length;
    if (selectedCount instanceof HTMLElement) selectedCount.textContent = `已選 ${checked} 件`;
  };

  selectAll.addEventListener('change', () => {
    documentCheckboxes.forEach((checkbox) => {
      if (checkbox instanceof HTMLInputElement) checkbox.checked = selectAll.checked;
    });
    refreshSelection();
  });
  documentCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', refreshSelection));
  refreshSelection();
})();

(() => {
  const labels = {
    unchanged: '維持不變',
    replace: '取代',
    add: '新增',
    remove: '移除',
  };
  document.querySelectorAll('[data-batch-mode]').forEach((control) => {
    if (!(control instanceof HTMLSelectElement)) return;
    const targetId = control.dataset.target;
    const target = targetId ? document.getElementById(targetId) : null;
    const panel = control.closest('[data-batch-panel]');
    const summaryLabel = panel?.querySelector('[data-batch-mode-label]');
    const refreshMode = () => {
      const mode = Object.hasOwn(labels, control.value) ? control.value : 'unchanged';
      if (target instanceof HTMLFieldSetElement) target.disabled = mode === 'unchanged';
      if (summaryLabel instanceof HTMLElement) summaryLabel.textContent = labels[mode];
    };
    control.addEventListener('change', refreshMode);
    refreshMode();
  });
})();

(() => {
  const page = document.querySelector('[data-operation-status-url]');
  if (!(page instanceof HTMLElement)) return;
  const statusUrl = page.dataset.operationStatusUrl;
  if (!statusUrl) return;

  const terminalStates = new Set(['preview-ready', 'completed', 'conflict', 'failed', 'cancelled']);
  if (terminalStates.has(page.dataset.operationStatus || '')) return;

  let failures = 0;
  const poll = async () => {
    try {
      const response = await fetch(statusUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const result = await response.json();
      failures = 0;
      if (result.status !== page.dataset.operationStatus || result.terminal === true) {
        window.location.reload();
        return;
      }
    } catch (error) {
      failures += 1;
      if (failures >= 5) return;
    }
    window.setTimeout(poll, 2500);
  };
  window.setTimeout(poll, 2500);
})();

(() => {
  const form = document.querySelector('[data-staging-upload-form]');
  if (!(form instanceof HTMLFormElement)) return;
  const fileInput = form.elements.namedItem('document_file');
  const targetInput = form.elements.namedItem('target_document_id');
  const changeRequestInput = form.elements.namedItem('change_request_id');
  const csrfInput = form.elements.namedItem('csrf_token');
  const progress = form.querySelector('[data-staging-progress]');
  const status = form.querySelector('[data-staging-status]');
  const button = form.querySelector('button[type="submit"]');
  if (!(fileInput instanceof HTMLInputElement) || !(csrfInput instanceof HTMLInputElement)) return;

  const post = async (url, data) => {
    const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => ({ ok: false, error: 'INVALID_RESPONSE' }));
    if (!response.ok || payload.ok !== true) throw new Error(payload.error || `HTTP_${response.status}`);
    return payload;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const file = fileInput.files?.[0];
    if (!(file instanceof File)) return;
    if (file.size < 1 || file.size > 157286400) {
      window.alert('單檔大小必須介於 1 byte 到 150 MB。');
      return;
    }
    if (button instanceof HTMLButtonElement) button.disabled = true;
    if (progress instanceof HTMLProgressElement) { progress.hidden = false; progress.value = 0; }
    if (status instanceof HTMLElement) status.textContent = '建立私有暫存工作區…';
    try {
      const create = new FormData();
      create.set('csrf_token', csrfInput.value);
      create.set('file_name', file.name);
      create.set('file_size_bytes', String(file.size));
      if (targetInput instanceof HTMLSelectElement) create.set('target_document_id', targetInput.value);
      if (changeRequestInput instanceof HTMLInputElement) create.set('change_request_id', changeRequestInput.value);
      const created = await post(form.dataset.createUrl || '', create);
      const workspace = created.workspace;
      const chunkSize = Number(workspace.chunk_size_bytes);
      const totalChunks = Number(workspace.total_chunks);
      for (let index = 0; index < totalChunks; index += 1) {
        if (status instanceof HTMLElement) status.textContent = `上傳分段 ${index + 1} / ${totalChunks}…`;
        const chunk = new FormData();
        chunk.set('csrf_token', csrfInput.value);
        chunk.set('id', workspace.id);
        chunk.set('index', String(index));
        chunk.set('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), `chunk-${index}.bin`);
        await post(form.dataset.chunkUrl || '', chunk);
        if (progress instanceof HTMLProgressElement) progress.value = Math.round(((index + 1) / totalChunks) * 90);
      }
      if (status instanceof HTMLElement) status.textContent = '驗證檔案並產生預覽…';
      const finalize = new FormData();
      finalize.set('csrf_token', csrfInput.value);
      finalize.set('id', workspace.id);
      const completed = await post(form.dataset.finalizeUrl || '', finalize);
      if (progress instanceof HTMLProgressElement) progress.value = 100;
      window.location.assign(completed.workspace.url);
    } catch (error) {
      if (status instanceof HTMLElement) status.textContent = '上傳或預覽失敗，請重新嘗試。';
      if (button instanceof HTMLButtonElement) button.disabled = false;
    }
  });
})();

(() => {
  const pendingCommit = document.querySelector('[data-staging-refresh]');
  if (!(pendingCommit instanceof HTMLElement)) return;
  window.setTimeout(() => window.location.reload(), 2500);
})();
