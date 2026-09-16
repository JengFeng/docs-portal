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
