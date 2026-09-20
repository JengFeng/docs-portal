(() => {
  'use strict';
  const tree = document.querySelector('[data-explorer-tree]');
  const input = document.querySelector('[data-explorer-search]');
  const clear = document.querySelector('[data-explorer-search-clear]');
  const resultPanel = document.querySelector('[data-explorer-search-results]');
  const resultBody = document.querySelector('[data-explorer-search-result-body]');
  if (!tree || !input || !clear || !resultPanel || !resultBody) return;
  const key = 'twwater-explorer-folder';
  const panels = [...document.querySelectorAll('[data-explorer-content]')];
  const buttons = [...document.querySelectorAll('[data-explorer-folder]')];
  const documents = [...document.querySelectorAll('[data-explorer-document]')];
  const portalRenderBreadcrumb = (target) => {
    const breadcrumb = target.querySelector('[data-explorer-breadcrumb]');
    if (!breadcrumb) return;
    breadcrumb.querySelectorAll('[data-explorer-folder]').forEach((button) => {
      button.classList.toggle('is-current', button.dataset.explorerFolder === target.dataset.explorerContent);
    });
  };
  const selectFolder = (id, persist = true) => {
    const target = panels.find((panel) => panel.dataset.explorerContent === id) || panels.find((panel) => panel.dataset.explorerContent === 'root');
    panels.forEach((panel) => { panel.hidden = panel !== target; });
    resultPanel.hidden = true;
    buttons.forEach((button) => button.classList.toggle('is-selected', button.dataset.explorerFolder === target.dataset.explorerContent));
    if (persist) { try { localStorage.setItem(key, target.dataset.explorerContent); } catch (_) {} }
    portalRenderBreadcrumb(target);
  };
  buttons.forEach((button) => button.addEventListener('click', () => selectFolder(button.dataset.explorerFolder || 'root')));
  const showSearch = () => {
    const query = input.value.trim().toLocaleLowerCase();
    if (!query) { selectFolder((() => { try { return localStorage.getItem(key) || 'root'; } catch (_) { return 'root'; } })(), false); return; }
    panels.forEach((panel) => { panel.hidden = true; }); resultPanel.hidden = false; resultBody.replaceChildren();
    const matches = documents.filter((row) => `${row.dataset.name || ''} ${row.dataset.path || ''}`.toLocaleLowerCase().includes(query));
    if (!matches.length) { resultBody.innerHTML = '<tr><td colspan="5">找不到符合的文件。</td></tr>'; return; }
    matches.forEach((row) => { const source = row.querySelector('a'); const cells = [...row.children].map((cell) => cell.textContent.trim()); const tr = document.createElement('tr'); tr.innerHTML = `<td></td><td></td><td></td><td></td><td></td>`; const link = source.cloneNode(true); tr.children[0].replaceChildren(link); tr.children[1].textContent = cells[1] || ''; tr.children[2].textContent = row.dataset.path || ''; tr.children[3].textContent = cells[2] || ''; tr.children[4].textContent = cells[3] || ''; resultBody.appendChild(tr); });
  };
  input.addEventListener('input', showSearch);
  clear.addEventListener('click', () => { input.value = ''; showSearch(); input.focus(); });
  let saved = 'root'; try { saved = localStorage.getItem(key) || 'root'; } catch (_) {} selectFolder(saved, false);
})();

(() => {
  'use strict';
  const panel = document.querySelector('[data-health-poll-url]');
  if (!panel) return;
  const refresh = async () => {
    try {
      const response = await fetch(panel.dataset.healthPollUrl || '', { credentials: 'same-origin' });
      if (!response.ok) return;
      const data = await response.json();
      panel.querySelectorAll('[data-health]').forEach((node) => {
        const key = node.dataset.health || '';
        if (Object.prototype.hasOwnProperty.call(data, key)) node.textContent = String(data[key]);
      });
    } catch (_) {}
  };
  setInterval(refresh, 60000);
})();

(() => {
  'use strict';
  const panel = document.querySelector('[data-sync-panel]');
  if (!panel) return;
  const form = panel.querySelector('[data-sync-request-form]');
  const submit = panel.querySelector('[data-sync-submit]');
  const feedback = panel.querySelector('[data-sync-feedback]');
  const status = document.querySelector('[data-sync-status]');
  const currentOperation = document.querySelector('[data-sync-current-operation]');
  const history = document.querySelector('[data-sync-history]');
  const lastUpdated = panel.querySelector('[data-sync-last-updated]');
  if (form && submit && feedback) {
    form.addEventListener('submit', () => {
      form.setAttribute('aria-busy', 'true');
      submit.disabled = true;
      submit.textContent = '正在送出…';
      feedback.textContent = '正在送出同步預覽請求，頁面即將更新。';
    });
  }
  const refresh = async () => {
    try {
      const response = await fetch(panel.dataset.syncPollUrl || '', { credentials: 'same-origin' });
      if (!response.ok) throw new Error('sync_poll_failed');
      const data = await response.json();
      if (status) status.textContent = `服務狀態：${data.status || 'unknown'}`;
      if (currentOperation && typeof data.current_html === 'string') currentOperation.innerHTML = data.current_html;
      if (history && panel.dataset.syncHistoryLive === '1' && data.history_live === true && typeof data.history_html === 'string') history.innerHTML = data.history_html;
      if (lastUpdated) lastUpdated.textContent = `最後更新：${new Date().toLocaleTimeString()}`;
      if (feedback && form?.getAttribute('aria-busy') !== 'true') feedback.textContent = '';
    } catch (_) {
      if (feedback) feedback.textContent = '同步狀態暫時無法更新；系統不會在狀態不明時自動執行同步。';
    }
  };
  setInterval(refresh, 5000);
})();
