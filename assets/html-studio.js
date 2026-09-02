(() => {
  'use strict';
  const root = document.querySelector('[data-studio-root]');
  if (!root) return;

  const $ = (selector, scope = root) => scope.querySelector(selector);
  const $$ = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));
  const storageKey = 'twwater-html-studio-draft-v1';
  const maxMarkupLength = 250000;
  const maxCssLength = 250000;
  const maxHtmlFileSize = 512 * 1024;
  const canvas = $('#studio-source-canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  const previewFrame = $('#studio-preview');
  const compareFrame = $('#studio-html-compare');
  const previewShell = $('[data-studio-view="preview"]');
  const sourceCompare = $('#studio-source-compare');
  const annotationLayer = $('[data-studio-annotation-layer]');
  const highlight = $('[data-studio-highlight]');
  const htmlCode = $('#studio-html-code');
  const cssCode = $('#studio-css-code');
  const intentInput = $('[data-studio-intent]');
  const saveState = $('[data-studio-save-state]');
  const canvasSelectionElement = $('[data-studio-canvas-selection]');
  const selectionDeleteButton = $('#studio-selection-delete');
  const initialMarkup = htmlCode.value;
  const initialCss = cssCode.value;
  let markup = initialMarkup;
  let css = initialCss;
  let mode = 'source';
  let drawTool = 'select';
  let drawStart = null;
  let drawHistory = [];
  let selectedElement = null;
  let annotationDrag = null;
  let annotationTool = 'rect';
  let annotationRecords = [];
  let selectedAnnotationId = null;
  let annotationDraftElement = null;
  let nextAnnotationNumber = 1;
  let annotationCount = 0;
  let generatedSource = '';
  let htmlGenerated = false;
  let studioEntryKind = 'image';
  let canvasSelection = null;
  let selectionAction = null;
  let draftSaveTimer = null;
  let restoredSourceImage = '';
  let restoredGeneratedSource = false;
  let restoredEntryKind = 'image';
  let restoredAnnotations = [];
  let annotationNormalizationPending = false;
  let generatedInstructionText = '';
  let generatedInstructionPayload = null;
  let entryOperationGeneration = 0;

  function cancelPendingEntryOperation() {
    entryOperationGeneration += 1;
  }

  function setSaveState(text, isError = false) {
    saveState.textContent = text;
    saveState.classList.toggle('is-error', isError);
  }

  function updateGeneratedAvailability() {
    $$('[data-studio-requires-generated]').forEach((control) => { control.disabled = !htmlGenerated; });
    $$('[data-studio-requires-comparison-source]').forEach((control) => { control.disabled = !htmlGenerated || studioEntryKind !== 'image'; });
    const status = $('[data-studio-generation-status]');
    if (status) status.textContent = !htmlGenerated
      ? '尚未產生或匯入 HTML；後續功能已鎖定。'
      : studioEntryKind === 'html'
        ? '既有 HTML 已安全匯入；預覽、標註、元件與程式碼已解鎖。因無原始大圖，大圖比較不適用。'
        : 'HTML 已產生；比較、預覽與標註功能已解鎖。';
  }

  function invalidateGeneratedHtml() {
    cancelPendingEntryOperation();
    htmlGenerated = false;
    studioEntryKind = 'image';
    generatedSource = '';
    updateGeneratedAvailability();
  }

  function sanitizeMarkup(input) {
    const parser = new DOMParser();
    const documentValue = parser.parseFromString(String(input).slice(0, maxMarkupLength), 'text/html');
    documentValue.querySelectorAll('script,style,iframe,object,embed,applet,link,meta,base,form,template,foreignObject').forEach((node) => node.remove());
    documentValue.querySelectorAll('*').forEach((element) => {
      Array.from(element.attributes).forEach((attribute) => {
        const name = attribute.name.toLowerCase();
        const value = attribute.value.trim();
        if (name.startsWith('on') || name === 'srcdoc' || name === 'style'
          || name === 'srcset' || name === 'ping' || name === 'action'
          || name === 'formaction' || name === 'background') {
          element.removeAttribute(attribute.name);
          return;
        }
        if ((name === 'href' || name === 'xlink:href') && !/^#[A-Za-z0-9_.:-]*$/.test(value)) {
          element.removeAttribute(attribute.name);
          return;
        }
        if ((name === 'src' || name === 'poster') && !/^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$/i.test(value)) {
          element.removeAttribute(attribute.name);
        }
      });
    });
    return documentValue.body.innerHTML;
  }

  function sanitizeCss(input) {
    const value = String(input).slice(0, maxCssLength);
    if (/@import\b|url\s*\(|expression\s*\(|behavior\s*:|-moz-binding\s*:/i.test(value)) {
      const error = new Error('CSS resource URLs and executable legacy properties are not allowed.');
      error.name = 'StudioValidationError';
      throw error;
    }
    return value;
  }

  function extractImportedHtml(input) {
    const source = String(input);
    const openingStyles = source.match(/<style\b/gi) || [];
    const closingStyles = source.match(/<\/style\s*>/gi) || [];
    const styleBlocks = [];
    const htmlWithoutStyles = source.replace(/<style\b[^>]*>([\s\S]*?)<\/style\s*>/gi, (_match, content) => { styleBlocks.push(content); return ''; });
    if (openingStyles.length !== closingStyles.length || styleBlocks.length !== openingStyles.length) {
      const error = new Error('Imported HTML has malformed style blocks.');
      error.name = 'StudioValidationError';
      throw error;
    }
    const importedCss = styleBlocks.join('\n');
    const safeCss = sanitizeCss(importedCss);
    const parser = new DOMParser();
    const documentValue = parser.parseFromString(htmlWithoutStyles, 'text/html');
    if (importedCss.length > maxCssLength || documentValue.body.innerHTML.length > maxMarkupLength) {
      const error = new Error('Imported HTML or CSS exceeds the editor limit.');
      error.name = 'StudioValidationError';
      throw error;
    }
    const safeMarkup = sanitizeMarkup(documentValue.body.innerHTML);
    if (safeMarkup.trim() === '') {
      const error = new Error('Imported HTML has no usable body content.');
      error.name = 'StudioValidationError';
      throw error;
    }
    return { markup: safeMarkup, css: safeCss };
  }

  function applyConstructedStyles(frame, cssText) {
    const frameDocument = frame.contentDocument;
    const Sheet = frame.contentWindow.CSSStyleSheet;
    const sheet = new Sheet();
    sheet.replaceSync(sanitizeCss(cssText));
    frameDocument.adoptedStyleSheets = [sheet];
  }

  function installPreviewBehavior(frame) {
    const frameDocument = frame.contentDocument;
    frameDocument.addEventListener('submit', (event) => event.preventDefault(), true);
    frameDocument.addEventListener('click', (event) => {
      const link = event.target.closest('a');
      if (link) event.preventDefault();
      if (frame === previewFrame && mode === 'inspect') inspectElement(event);
    }, true);
    const search = frameDocument.querySelector('#generated-station-search');
    if (search) {
      search.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('zh-Hant');
        frameDocument.querySelectorAll('tbody tr').forEach((row) => {
          row.hidden = query !== '' && !row.textContent.toLocaleLowerCase('zh-Hant').includes(query);
        });
      });
    }
    const report = frameDocument.querySelector('#generated-report-button');
    if (report) report.addEventListener('click', () => setSaveState('真實 HTML 按鈕已操作'));
  }

  function renderFrame(frame) {
    const safeMarkup = sanitizeMarkup(markup);
    const frameDocument = frame.contentDocument;
    frameDocument.open();
    frameDocument.write('<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; base-uri \'none\'; form-action \'none\'; object-src \'none\'; script-src \'none\'; img-src data:; font-src data:; style-src \'unsafe-inline\'"><title>HTML 工作室預覽</title></head><body></body></html>');
    frameDocument.close();
    frameDocument.body.innerHTML = safeMarkup;
    applyConstructedStyles(frame, css);
    installPreviewBehavior(frame);
  }

  function renderAll() {
    try {
      renderFrame(previewFrame);
      renderFrame(compareFrame);
      scaleCompare();
      setSaveState('真實 HTML 已更新');
      return true;
    } catch (error) {
      if (error && error.name === 'StudioValidationError') console.warn('HTML studio input rejected:', error.message);
      else console.error('HTML studio preview failed', error);
      setSaveState('HTML／CSS 無法套用', true);
      return false;
    }
  }

  function scaleCompare() {
    const surface = compareFrame.parentElement;
    if (!surface || !surface.clientWidth) return;
    compareFrame.style.transform = `scale(${surface.clientWidth / 1200})`;
  }

  const annotationTypeLabels = { rect: '框選', circle: '圓圈', arrow: '箭頭', number: '數字' };

  function makeAnnotationId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return `ann-${window.crypto.randomUUID()}`;
    return `ann-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function normalizeAnnotationRecord(raw, index) {
    if (!raw || typeof raw !== 'object') return null;
    const type = Object.hasOwn(annotationTypeLabels, raw.type) ? raw.type : 'rect';
    const annotationId = typeof raw.annotationId === 'string' && /^ann-[A-Za-z0-9_-]{6,100}$/.test(raw.annotationId) ? raw.annotationId : makeAnnotationId();
    const record = {
      annotationId,
      number: index + 1,
      type,
      text: String(raw.text ?? raw.label ?? '').slice(0, 2000),
      x: Number(raw.x), y: Number(raw.y), width: Number(raw.width), height: Number(raw.height),
      x2: Number(raw.x2), y2: Number(raw.y2)
    };
    if (!Number.isFinite(record.x) || !Number.isFinite(record.y) || record.x < 0 || record.x > 1 || record.y < 0 || record.y > 1) return null;
    if (type === 'number') return record;
    if (type === 'arrow') {
      if (!Number.isFinite(record.x2)) record.x2 = Math.min(1, record.x + Math.max(0.02, Number(raw.width) || 0.1));
      if (!Number.isFinite(record.y2)) record.y2 = Math.min(1, record.y + Math.max(0.02, Number(raw.height) || 0.1));
      if (record.x2 < 0 || record.x2 > 1 || record.y2 < 0 || record.y2 > 1) return null;
      return record;
    }
    if (!Number.isFinite(record.width) || !Number.isFinite(record.height) || record.width <= 0 || record.height <= 0 || record.x + record.width > 1.001 || record.y + record.height > 1.001) return null;
    return record;
  }

  function serializeAnnotationRecord(record) {
    const base = { annotationId: record.annotationId, number: record.number, type: record.type, text: record.text, x: record.x, y: record.y };
    if (record.type === 'arrow') return { ...base, x2: record.x2, y2: record.y2 };
    if (record.type === 'number') return base;
    return { ...base, width: record.width, height: record.height };
  }

  function annotationRecordNeedsNormalization(raw, record) {
    const canonical = serializeAnnotationRecord(record);
    const allowed = new Set(Object.keys(canonical));
    if (Object.keys(raw).some((key) => !allowed.has(key))) return true;
    const projection = {};
    Object.keys(canonical).forEach((key) => { projection[key] = raw[key]; });
    return JSON.stringify(projection) !== JSON.stringify(canonical);
  }

  function serializeAnnotations() {
    return annotationRecords.slice(0, 100).map(serializeAnnotationRecord);
  }

  function selectAnnotation(annotationId, options = {}) {
    if (!annotationRecords.some((record) => record.annotationId === annotationId)) return;
    selectedAnnotationId = annotationId;
    $$('[data-annotation-id]', annotationLayer).forEach((shape) => shape.classList.toggle('is-selected', shape.dataset.annotationId === annotationId));
    $$('[data-annotation-card]').forEach((card) => card.setAttribute('aria-current', card.dataset.annotationId === annotationId ? 'true' : 'false'));
    const card = $(`[data-annotation-card][data-annotation-id="${CSS.escape(annotationId)}"]`);
    if (card && options.scroll) card.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    if (card && options.focus) requestAnimationFrame(() => card.querySelector('textarea')?.focus({ preventScroll: true }));
  }

  function createAnnotationShape(record, draft = false) {
    const shape = document.createElement('div');
    shape.className = `studio-annotation-shape studio-annotation-${record.type}${draft ? ' is-draft' : ''}`;
    shape.dataset.annotationType = record.type;
    shape.dataset.number = String(record.number || nextAnnotationNumber);
    if (!draft) shape.dataset.annotationId = record.annotationId;
    if (record.type === 'number') {
      shape.textContent = String(record.number || nextAnnotationNumber);
      shape.style.left = `${record.x * 100}%`; shape.style.top = `${record.y * 100}%`;
    } else {
      const badge = document.createElement('span'); badge.className = 'studio-annotation-badge'; badge.textContent = String(record.number || nextAnnotationNumber); shape.append(badge);
      shape.style.left = `${record.x * 100}%`; shape.style.top = `${record.y * 100}%`;
      if (record.type === 'arrow') {
        const dx = (record.x2 - record.x) * annotationLayer.clientWidth;
        const dy = (record.y2 - record.y) * annotationLayer.clientHeight;
        shape.style.width = `${Math.hypot(dx, dy)}px`;
        shape.style.transform = `rotate(${Math.atan2(dy, dx)}rad)`;
      } else {
        shape.style.width = `${record.width * 100}%`; shape.style.height = `${record.height * 100}%`;
      }
    }
    if (!draft) {
      shape.classList.toggle('is-selected', record.annotationId === selectedAnnotationId);
      shape.addEventListener('click', (event) => { event.stopPropagation(); selectAnnotation(record.annotationId, { scroll: true }); });
    }
    return shape;
  }

  function renderAnnotationShapes() {
    annotationLayer.replaceChildren();
    annotationRecords.forEach((record) => annotationLayer.append(createAnnotationShape(record)));
  }

  function renderAnnotationList() {
    const list = $('[data-studio-annotation-list]');
    list.replaceChildren();
    if (annotationRecords.length === 0) {
      const empty = document.createElement('p'); empty.className = 'muted'; empty.dataset.studioAnnotationEmpty = ''; empty.textContent = '尚未建立標註。'; list.append(empty); return;
    }
    annotationRecords.forEach((record) => {
      const card = document.createElement('article'); card.className = 'studio-annotation-card'; card.dataset.annotationCard = ''; card.dataset.annotationId = record.annotationId; card.dataset.number = String(record.number); card.setAttribute('aria-current', record.annotationId === selectedAnnotationId ? 'true' : 'false');
      const header = document.createElement('header');
      const number = document.createElement('span'); number.className = 'studio-annotation-card-number'; number.textContent = String(record.number);
      const type = document.createElement('span'); type.className = 'studio-annotation-card-type'; type.textContent = annotationTypeLabels[record.type];
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '刪除'; remove.setAttribute('aria-label', `刪除標註 ${record.number}`); remove.addEventListener('click', () => { annotationRecords = annotationRecords.filter((item) => item.annotationId !== record.annotationId); annotationRecords.forEach((item, index) => { item.number = index + 1; }); nextAnnotationNumber = annotationRecords.length + 1; if (selectedAnnotationId === record.annotationId) selectedAnnotationId = annotationRecords[0]?.annotationId || null; invalidateInstructions(); renderAnnotations(); scheduleDraftSave(); });
      header.append(number, type, remove);
      const textarea = document.createElement('textarea'); textarea.value = record.text; textarea.placeholder = '輸入此編號要修改的內容'; textarea.setAttribute('aria-label', `標註 ${record.number} 修改說明`);
      textarea.addEventListener('focus', () => selectAnnotation(record.annotationId));
      textarea.addEventListener('input', () => { record.text = textarea.value.slice(0, 2000); invalidateInstructions(); scheduleDraftSave(); });
      card.addEventListener('click', (event) => { if (event.target !== remove) selectAnnotation(record.annotationId); });
      card.append(header, textarea); list.append(card);
    });
  }

  function renderAnnotations() {
    annotationCount = annotationRecords.length;
    $('[data-studio-annotation-count]').textContent = `${annotationCount} 個標註`;
    renderAnnotationShapes(); renderAnnotationList();
  }

  function invalidateInstructions() {
    generatedInstructionText = '';
    generatedInstructionPayload = null;
    const output = $('[data-studio-instruction-output]');
    if (output) output.value = '';
    $$('[data-studio-copy-instructions],[data-studio-download-instructions],[data-studio-download-json]').forEach((button) => { button.disabled = true; });
  }

  function percent(value) { return Math.round(Number(value) * 1000) / 10; }

  function instructionGeometry(record) {
    if (record.type === 'number') return { xPercent: percent(record.x), yPercent: percent(record.y) };
    if (record.type === 'arrow') return { startXPercent: percent(record.x), startYPercent: percent(record.y), endXPercent: percent(record.x2), endYPercent: percent(record.y2) };
    return { xPercent: percent(record.x), yPercent: percent(record.y), widthPercent: percent(record.width), heightPercent: percent(record.height) };
  }

  function buildInstructionPayload() {
    const device = $('[data-studio-device].is-active')?.dataset.studioDevice || 'desktop';
    return {
      version: 1,
      project: 'TWWATER HTML 工作室',
      target: '目前工作室中的 HTML/CSS',
      logicalViewport: { width: 1200, height: 675 },
      previewDevice: device,
      intent: intentInput.value.trim().slice(0, 4000),
      annotations: annotationRecords.map((record) => ({ annotationId: record.annotationId, number: record.number, type: record.type, typeLabel: annotationTypeLabels[record.type], text: record.text.trim(), geometry: instructionGeometry(record) })),
      safetyBoundaries: ['只修改標註與文字明確要求的範圍', '保留未標註內容與既有功能', '維持桌面、平板與手機 RWD', '如位置證據互相衝突，停止修改並回報']
    };
  }

  function geometryDescription(annotation) {
    const geometry = annotation.geometry;
    if (annotation.type === 'number') return `位置：左側 ${geometry.xPercent}%，上方 ${geometry.yPercent}%`;
    if (annotation.type === 'arrow') return `箭頭：(${geometry.startXPercent}%, ${geometry.startYPercent}%) → (${geometry.endXPercent}%, ${geometry.endYPercent}%)`;
    return `範圍：左側 ${geometry.xPercent}%，上方 ${geometry.yPercent}%，寬 ${geometry.widthPercent}%，高 ${geometry.heightPercent}%`;
  }

  function buildInstructionText(payload) {
    const lines = [
      'TWWATER HTML 工作室修改指令',
      '',
      `目標：${payload.target}`,
      `原始設計目的：${payload.intent || '未填寫'}`,
      `比較基準：原始完整大圖與真實 HTML，邏輯尺寸 ${payload.logicalViewport.width} × ${payload.logicalViewport.height}`,
      `目前檢視裝置：${payload.previewDevice}`,
      '',
      '逐項修改：'
    ];
    payload.annotations.forEach((annotation) => {
      lines.push('', `標註 #${annotation.number}（${annotation.typeLabel}）`, geometryDescription(annotation), `修改內容：${annotation.text}`);
    });
    lines.push('', '共同驗收與安全邊界：');
    payload.safetyBoundaries.forEach((boundary, index) => lines.push(`${index + 1}. ${boundary}`));
    return lines.join('\n');
  }

  function downloadStudioFile(filename, content, type) {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob); const anchor = document.createElement('a');
    anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 0);
  }

  function restoreAnnotationRecords(records) {
    const seen = new Set();
    let normalized = records.length > 100;
    annotationRecords = [];
    records.slice(0, 100).forEach((raw, index) => {
      const record = normalizeAnnotationRecord(raw, index);
      if (!record) { normalized = true; return; }
      const originalId = typeof raw.annotationId === 'string' ? raw.annotationId : '';
      if (record.annotationId !== originalId || seen.has(record.annotationId)) {
        do { record.annotationId = makeAnnotationId(); } while (seen.has(record.annotationId));
        normalized = true;
      }
      record.number = annotationRecords.length + 1;
      if (annotationRecordNeedsNormalization(raw, record)) normalized = true;
      seen.add(record.annotationId);
      annotationRecords.push(record);
    });
    nextAnnotationNumber = annotationRecords.length + 1;
    selectedAnnotationId = annotationRecords[0]?.annotationId || null;
    annotationNormalizationPending = normalized;
    renderAnnotations();
  }

  function saveDraft(options = {}) {
    try {
      const sourceImage = canvas.toDataURL('image/webp', 0.92);
      if (sourceImage.length > 3500000) throw new Error('Source image exceeds browser draft limit.');
      const payload = JSON.stringify({ version: 2, markup, css, intent: intentInput.value.slice(0, 4000), sourceImage, hasGeneratedSource: htmlGenerated, entryKind: studioEntryKind, annotations: serializeAnnotations() });
      localStorage.setItem(storageKey, payload);
      if (!options.preserveStatus) setSaveState('瀏覽器草稿已儲存（含起點大圖）');
    } catch (error) {
      console.warn('HTML studio draft was not stored', error);
      if (!options.preserveStatus) setSaveState('瀏覽器草稿未儲存', true);
    }
  }

  function scheduleDraftSave() {
    window.clearTimeout(draftSaveTimer);
    draftSaveTimer = window.setTimeout(saveDraft, 220);
  }

  function restoreDraft() {
    try {
      const raw = localStorage.getItem(storageKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && (parsed.version === 1 || parsed.version === 2) && typeof parsed.markup === 'string' && typeof parsed.css === 'string' && parsed.markup.length <= maxMarkupLength && parsed.css.length <= maxCssLength) {
        markup = sanitizeMarkup(parsed.markup);
        css = sanitizeCss(parsed.css);
        htmlCode.value = markup;
        cssCode.value = css;
        if (typeof parsed.intent === 'string') intentInput.value = parsed.intent.slice(0, 4000);
        if (parsed.version === 2 && typeof parsed.sourceImage === 'string' && parsed.sourceImage.length <= 3500000 && /^data:image\/(?:webp|png|jpeg);base64,[A-Za-z0-9+/=\s]+$/i.test(parsed.sourceImage)) {
          restoredSourceImage = parsed.sourceImage;
          restoredGeneratedSource = parsed.hasGeneratedSource === true;
          restoredEntryKind = parsed.entryKind === 'html' ? 'html' : 'image';
        }
        restoredAnnotations = Array.isArray(parsed.annotations) ? parsed.annotations : [];
        setSaveState('已還原瀏覽器草稿');
      }
    } catch (error) {
      console.warn('HTML studio draft was invalid', error);
      localStorage.removeItem(storageKey);
    }
  }

  function restoreSourceCanvas() {
    if (!restoredSourceImage) return false;
    const operationGeneration = ++entryOperationGeneration;
    const image = new Image();
    image.onload = () => {
      if (operationGeneration !== entryOperationGeneration) return;
      context.clearRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      generatedSource = restoredGeneratedSource && restoredEntryKind === 'image' ? restoredSourceImage : '';
      htmlGenerated = restoredGeneratedSource;
      studioEntryKind = restoredEntryKind;
      if (generatedSource) sourceCompare.src = generatedSource;
      updateGeneratedAvailability();
      if (annotationNormalizationPending) {
        annotationNormalizationPending = false;
        saveDraft();
        setSaveState('已還原並正規化瀏覽器草稿（含起點大圖）');
      } else setSaveState('已還原瀏覽器草稿（含起點大圖）');
    };
    image.onerror = () => { if (operationGeneration !== entryOperationGeneration) return; localStorage.removeItem(storageKey); setSaveState('起點大圖草稿無法還原', true); };
    image.src = restoredSourceImage;
    return true;
  }

  function drawInitialSource() {
    context.fillStyle = '#f3f6f4'; context.fillRect(0, 0, 1200, 675);
    context.fillStyle = '#173b31'; context.fillRect(0, 0, 1200, 88);
    context.fillStyle = '#e2a84b'; context.font = '700 20px Segoe UI'; context.fillText('TWWATER · 供水監測入口草圖', 54, 54);
    context.fillStyle = '#17211d'; context.font = '700 44px Microsoft JhengHei'; context.fillText('今天的供水狀態，一眼看清楚', 54, 150);
    context.fillStyle = '#66736d'; context.font = '24px Microsoft JhengHei'; context.fillText('先完成一張完整畫面，再轉成真正 HTML。', 54, 190);
    context.fillStyle = '#173b31'; context.fillRect(928, 122, 216, 58);
    context.fillStyle = '#fff'; context.font = '700 20px Microsoft JhengHei'; context.fillText('開啟今日報告', 970, 159);
    [['正常測站', '18'], ['需要注意', '2'], ['最後更新', '10:42']].forEach((entry, index) => {
      const x = 54 + index * 365;
      context.fillStyle = '#fff'; context.fillRect(x, 232, 335, 118);
      context.strokeStyle = '#d8dfdb'; context.strokeRect(x, 232, 335, 118);
      context.fillStyle = '#66736d'; context.font = '18px Microsoft JhengHei'; context.fillText(entry[0], x + 20, 265);
      context.fillStyle = '#17211d'; context.font = '700 38px Segoe UI'; context.fillText(entry[1], x + 20, 315);
    });
    context.fillStyle = '#fff'; context.fillRect(54, 382, 1090, 238);
    context.strokeStyle = '#d8dfdb'; context.strokeRect(54, 382, 1090, 238);
    context.fillStyle = '#17211d'; context.font = '700 28px Microsoft JhengHei'; context.fillText('測站狀態', 78, 426);
    context.font = '700 16px Microsoft JhengHei';
    ['測站', '區域', '壓力', '狀態', '更新時間'].forEach((text, index) => context.fillText(text, 78 + index * 205, 470));
    context.font = '18px Microsoft JhengHei';
    [['北區加壓站', '北區', '2.81', '正常', '10:42'], ['東門監測點', '東區', '1.92', '注意', '10:41'], ['文山配水池', '南區', '2.55', '正常', '10:42']].forEach((row, rowIndex) => row.forEach((text, index) => context.fillText(text, 78 + index * 205, 515 + rowIndex * 42)));
  }

  function canvasPoint(event, target = canvas) {
    const rectangle = target.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(target.width, (event.clientX - rectangle.left) * target.width / rectangle.width)),
      y: Math.max(0, Math.min(target.height, (event.clientY - rectangle.top) * target.height / rectangle.height))
    };
  }

  function saveDrawState() {
    drawHistory.push(context.getImageData(0, 0, canvas.width, canvas.height));
    if (drawHistory.length > 20) drawHistory.shift();
  }

  function updateCanvasSelection(selection = canvasSelection) {
    canvasSelection = selection;
    const visible = selection && selection.width >= 2 && selection.height >= 2;
    canvasSelectionElement.hidden = !visible;
    selectionDeleteButton.disabled = !visible;
    if (!visible) return;
    const scaleX = canvas.clientWidth / canvas.width;
    const scaleY = canvas.clientHeight / canvas.height;
    canvasSelectionElement.style.left = `${canvas.offsetLeft + selection.x * scaleX}px`;
    canvasSelectionElement.style.top = `${canvas.offsetTop + selection.y * scaleY}px`;
    canvasSelectionElement.style.width = `${selection.width * scaleX}px`;
    canvasSelectionElement.style.height = `${selection.height * scaleY}px`;
  }

  function clearCanvasSelection() {
    selectionAction = null;
    updateCanvasSelection(null);
  }

  function selectionFromPoints(start, end) {
    const x = Math.max(0, Math.floor(Math.min(start.x, end.x)));
    const y = Math.max(0, Math.floor(Math.min(start.y, end.y)));
    const right = Math.min(canvas.width, Math.ceil(Math.max(start.x, end.x)));
    const bottom = Math.min(canvas.height, Math.ceil(Math.max(start.y, end.y)));
    return { x, y, width: right - x, height: bottom - y };
  }

  function pointInsideSelection(point, selection) {
    return selection && point.x >= selection.x && point.x <= selection.x + selection.width && point.y >= selection.y && point.y <= selection.y + selection.height;
  }

  function deleteCanvasSelection() {
    if (!canvasSelection) return;
    saveDrawState();
    context.fillStyle = '#fff';
    context.fillRect(canvasSelection.x, canvasSelection.y, canvasSelection.width, canvasSelection.height);
    clearCanvasSelection();
    invalidateGeneratedHtml();
    scheduleDraftSave();
  }

  $$('[data-draw-tool]').forEach((button) => button.addEventListener('click', () => {
    drawTool = button.dataset.drawTool;
    $$('[data-draw-tool]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
    canvas.style.cursor = drawTool === 'select' ? 'default' : 'crosshair';
    if (drawTool !== 'select') clearCanvasSelection();
  }));

  canvas.addEventListener('pointerdown', (event) => {
    const point = canvasPoint(event);
    if (drawTool === 'select') {
      if (pointInsideSelection(point, canvasSelection)) {
        saveDrawState();
        selectionAction = 'move';
        drawStart = {
          ...point,
          image: context.getImageData(0, 0, canvas.width, canvas.height),
          originalSelection: { ...canvasSelection },
          pixels: context.getImageData(canvasSelection.x, canvasSelection.y, canvasSelection.width, canvasSelection.height)
        };
      } else {
        clearCanvasSelection();
        selectionAction = 'select';
        drawStart = { ...point };
      }
      try { canvas.setPointerCapture(event.pointerId); } catch (_) {}
      return;
    }
    clearCanvasSelection();
    if (drawTool === 'text') {
      const text = window.prompt('請輸入要放在大圖上的文字：', '新的說明');
      if (text) {
        saveDrawState(); context.fillStyle = '#173b31'; context.font = '700 28px Microsoft JhengHei'; context.fillText(text.slice(0, 80), point.x, point.y);
        invalidateGeneratedHtml(); scheduleDraftSave();
      }
      return;
    }
    saveDrawState();
    drawStart = { ...point, image: context.getImageData(0, 0, canvas.width, canvas.height) };
    if (drawTool === 'pen') {
      context.beginPath(); context.moveTo(point.x, point.y); context.strokeStyle = '#d05f4c'; context.lineWidth = 7; context.lineCap = 'round';
    }
    try { canvas.setPointerCapture(event.pointerId); } catch (_) {}
  });

  canvas.addEventListener('pointermove', (event) => {
    if (!drawStart) return;
    const point = canvasPoint(event);
    if (drawTool === 'select' && selectionAction === 'select') {
      updateCanvasSelection(selectionFromPoints(drawStart, point));
      return;
    }
    if (drawTool === 'select' && selectionAction === 'move') {
      const original = drawStart.originalSelection;
      const x = Math.max(0, Math.min(canvas.width - original.width, Math.round(original.x + point.x - drawStart.x)));
      const y = Math.max(0, Math.min(canvas.height - original.height, Math.round(original.y + point.y - drawStart.y)));
      context.putImageData(drawStart.image, 0, 0);
      context.fillStyle = '#fff'; context.fillRect(original.x, original.y, original.width, original.height);
      context.putImageData(drawStart.pixels, x, y);
      updateCanvasSelection({ x, y, width: original.width, height: original.height });
      return;
    }
    if (drawTool === 'pen') { context.lineTo(point.x, point.y); context.stroke(); }
    if (drawTool === 'rect') {
      context.putImageData(drawStart.image, 0, 0); context.strokeStyle = '#d05f4c'; context.lineWidth = 6; context.setLineDash([14, 8]);
      context.strokeRect(drawStart.x, drawStart.y, point.x - drawStart.x, point.y - drawStart.y); context.setLineDash([]);
    }
  });
  canvas.addEventListener('pointerup', (event) => {
    if (!drawStart) return;
    const point = canvasPoint(event);
    if (drawTool === 'select' && selectionAction === 'select') {
      const selection = selectionFromPoints(drawStart, point);
      updateCanvasSelection(selection.width >= 8 && selection.height >= 8 ? selection : null);
    } else if (drawTool === 'select' && selectionAction === 'move') {
      invalidateGeneratedHtml(); scheduleDraftSave();
    } else {
      invalidateGeneratedHtml(); scheduleDraftSave();
    }
    selectionAction = null;
    drawStart = null;
  });
  canvas.addEventListener('pointercancel', () => {
    if (drawStart && drawStart.image) context.putImageData(drawStart.image, 0, 0);
    if (drawStart && drawStart.originalSelection) updateCanvasSelection(drawStart.originalSelection);
    else if (selectionAction === 'select') clearCanvasSelection();
    selectionAction = null; drawStart = null;
  });

  selectionDeleteButton.addEventListener('click', deleteCanvasSelection);
  $('#studio-draw-undo').addEventListener('click', () => { const state = drawHistory.pop(); if (state) { context.putImageData(state, 0, 0); clearCanvasSelection(); invalidateGeneratedHtml(); scheduleDraftSave(); } });
  $('#studio-draw-clear').addEventListener('click', () => { saveDrawState(); context.fillStyle = '#fff'; context.fillRect(0, 0, 1200, 675); clearCanvasSelection(); invalidateGeneratedHtml(); scheduleDraftSave(); });
  $('#studio-image-upload').addEventListener('change', async (event) => {
    const file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    const operationGeneration = ++entryOperationGeneration;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) {
      window.clearTimeout(draftSaveTimer); draftSaveTimer = null; saveDraft({ preserveStatus: true });
      setSaveState('圖片必須是 PNG／JPEG／WebP，且不超過 8 MiB', true); return;
    }
    try {
      const image = await createImageBitmap(file);
      if (operationGeneration !== entryOperationGeneration) { image.close(); return; }
      if (image.width > 4096 || image.height > 4096) {
        image.close(); window.clearTimeout(draftSaveTimer); draftSaveTimer = null; saveDraft({ preserveStatus: true });
        setSaveState('圖片尺寸不可超過 4096 × 4096', true); return;
      }
      saveDrawState(); context.fillStyle = '#fff'; context.fillRect(0, 0, 1200, 675); clearCanvasSelection();
      const scale = Math.min(1200 / image.width, 675 / image.height); const width = image.width * scale; const height = image.height * scale;
      context.drawImage(image, (1200 - width) / 2, (675 - height) / 2, width, height); image.close(); invalidateGeneratedHtml(); scheduleDraftSave(); setSaveState('圖片已放入起點大圖');
    } catch (error) {
      if (operationGeneration !== entryOperationGeneration) return;
      window.clearTimeout(draftSaveTimer); draftSaveTimer = null; saveDraft({ preserveStatus: true });
      console.warn('HTML studio image decode failed', error); setSaveState('圖片無法解碼', true);
    }
  });

  $('#studio-html-upload').addEventListener('change', async (event) => {
    const file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    const operationGeneration = ++entryOperationGeneration;
    if (!/\.html?$/i.test(file.name) || file.size > maxHtmlFileSize) {
      window.clearTimeout(draftSaveTimer);
      draftSaveTimer = null;
      saveDraft({ preserveStatus: true });
      setSaveState('HTML 檔必須是 .html／.htm，且不超過 512 KiB', true);
      return;
    }
    window.clearTimeout(draftSaveTimer);
    draftSaveTimer = null;
    const previousMarkup = markup; const previousCss = css;
    try {
      const importedText = await file.text();
      if (operationGeneration !== entryOperationGeneration) return;
      const imported = extractImportedHtml(importedText);
      markup = imported.markup; css = imported.css;
      if (!renderAll()) throw new Error('Imported HTML could not be rendered safely.');
      htmlCode.value = markup; cssCode.value = css;
      htmlGenerated = true; studioEntryKind = 'html'; generatedSource = ''; sourceCompare.removeAttribute('src');
      annotationRecords = []; selectedAnnotationId = null; nextAnnotationNumber = 1; invalidateInstructions(); renderAnnotations();
      updateGeneratedAvailability(); setMode('preview'); saveDraft();
      setSaveState(`HTML 檔「${file.name.slice(0, 120)}」已安全匯入並設為初始內容`);
    } catch (error) {
      if (operationGeneration !== entryOperationGeneration) return;
      markup = previousMarkup; css = previousCss; htmlCode.value = markup; cssCode.value = css; renderAll();
      saveDraft({ preserveStatus: true });
      console.warn('HTML studio import rejected', error);
      setSaveState('HTML 檔無法匯入：請移除外部 CSS 資源、超量內容或不安全語法', true);
    }
  });

  function updateSteps(activeIndex) {
    $$('.studio-steps li').forEach((item, index) => { item.classList.toggle('is-active', index === activeIndex); item.classList.toggle('is-done', index < activeIndex); });
  }

  function setMode(nextMode) {
    if (!htmlGenerated && nextMode !== 'source') {
      setSaveState('請先完成起點大圖並產生 HTML，或直接匯入既有 HTML 檔案。', true);
      updateGeneratedAvailability();
      return;
    }
    if (nextMode === 'compare' && studioEntryKind !== 'image') {
      setSaveState('匯入的 HTML 沒有原始大圖基準，請使用真實預覽、標註或程式碼模式。', true);
      updateGeneratedAvailability();
      return;
    }
    mode = nextMode;
    $$('[data-studio-mode]').forEach((button) => button.classList.toggle('is-active', button.dataset.studioMode === mode));
    $$('[data-studio-view]').forEach((view) => { view.hidden = view.dataset.studioView !== (mode === 'source' || mode === 'compare' ? mode : 'preview'); });
    $$('[data-studio-panel]').forEach((panel) => { panel.hidden = panel.dataset.studioPanel !== mode; });
    annotationLayer.hidden = mode !== 'annotate';
    highlight.hidden = true;
    selectedElement = null;
    const copy = {
      source: ['起點大圖', '使用標準工具完成一張完整畫面，不必先拆成 HTML 元件。'],
      compare: ['原始大圖／真實 HTML', '兩側使用相同 1200 × 675 邏輯尺寸，直接比較差異。'],
      preview: ['真實 HTML 預覽', '中央 iframe 執行實際 DOM 與 CSS，不是圖片。'],
      annotate: ['畫面標註', '在真實 HTML 上圈出不一致的位置。'],
      inspect: ['選取真實元件', '點擊 iframe 內的 HTML 元件並直接調整文字。'],
      code: ['HTML／CSS', '修改後套用至真實預覽；使用者 JavaScript 不會執行。']
    }[mode];
    $('[data-studio-stage-title]').textContent = copy[0]; $('[data-studio-stage-help]').textContent = copy[1];
    if (mode === 'compare') { generatedSource = canvas.toDataURL('image/png'); sourceCompare.src = generatedSource; renderAll(); requestAnimationFrame(scaleCompare); updateSteps(1); }
    if (['preview', 'annotate', 'inspect', 'code'].includes(mode)) { renderFrame(previewFrame); updateSteps(mode === 'preview' ? 2 : mode === 'code' ? 4 : 2); }
    if (mode === 'annotate') requestAnimationFrame(renderAnnotationShapes);
  }

  $$('[data-studio-mode]').forEach((button) => button.addEventListener('click', () => setMode(button.dataset.studioMode)));
  $$('[data-studio-go]').forEach((button) => button.addEventListener('click', () => setMode(button.dataset.studioGo)));
  $$('[data-studio-device]').forEach((button) => button.addEventListener('click', () => {
    $$('[data-studio-device]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
    previewShell.classList.remove('is-tablet', 'is-mobile');
    if (button.dataset.studioDevice !== 'desktop') previewShell.classList.add(`is-${button.dataset.studioDevice}`);
    $('[data-studio-device-label]').textContent = button.dataset.studioDevice === 'desktop' ? 'DESKTOP' : button.dataset.studioDevice === 'tablet' ? 'TABLET · 768' : 'MOBILE · 390';
    invalidateInstructions();
    requestAnimationFrame(renderAnnotationShapes);
  }));

  $('#studio-generate-html').addEventListener('click', () => {
    cancelPendingEntryOperation();
    generatedSource = canvas.toDataURL('image/png');
    sourceCompare.src = generatedSource;
    htmlGenerated = true;
    studioEntryKind = 'image';
    updateGeneratedAvailability();
    renderAll(); setMode('compare'); saveDraft();
  });

  function annotationPoint(event) {
    const rectangle = annotationLayer.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(rectangle.width, event.clientX - rectangle.left)),
      y: Math.max(0, Math.min(rectangle.height, event.clientY - rectangle.top))
    };
  }

  function annotationGeometry(type, start, end) {
    const width = annotationLayer.clientWidth; const height = annotationLayer.clientHeight;
    if (width <= 0 || height <= 0) return null;
    if (type === 'number') return { x: end.x / width, y: end.y / height };
    if (type === 'arrow') return { x: start.x / width, y: start.y / height, x2: end.x / width, y2: end.y / height };
    const left = Math.min(start.x, end.x); const top = Math.min(start.y, end.y);
    return { x: left / width, y: top / height, width: Math.abs(end.x - start.x) / width, height: Math.abs(end.y - start.y) / height };
  }

  function createAnnotationRecord(type, start, end) {
    if (annotationRecords.length >= 100) { setSaveState('標註上限為 100 筆', true); return null; }
    const geometry = annotationGeometry(type, start, end);
    if (!geometry) return null;
    if ((type === 'rect' || type === 'circle') && (Math.abs(end.x - start.x) < 8 || Math.abs(end.y - start.y) < 8)) return null;
    if (type === 'arrow' && Math.hypot(end.x - start.x, end.y - start.y) < 12) return null;
    const annotationId = makeAnnotationId();
    const record = { annotationId, number: nextAnnotationNumber, type, text: '', ...geometry };
    annotationRecords.push(record); nextAnnotationNumber += 1; selectedAnnotationId = annotationId; invalidateInstructions();
    renderAnnotations(); selectAnnotation(annotationId, { focus: true, scroll: true }); scheduleDraftSave();
    return record;
  }

  function renderAnnotationDraft(type, start, end) {
    annotationDraftElement?.remove(); annotationDraftElement = null;
    const geometry = annotationGeometry(type, start, end);
    if (!geometry) return;
    annotationDraftElement = createAnnotationShape({ number: nextAnnotationNumber, type, ...geometry }, true);
    annotationLayer.append(annotationDraftElement);
  }

  $$('[data-annotation-tool]').forEach((button) => button.addEventListener('click', () => {
    annotationTool = button.dataset.annotationTool;
    $$('[data-annotation-tool]').forEach((candidate) => {
      const active = candidate === button; candidate.classList.toggle('is-active', active); candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }));

  annotationLayer.addEventListener('pointerdown', (event) => {
    if (event.target !== annotationLayer) return;
    const start = annotationPoint(event);
    annotationDrag = { start, type: annotationTool, pointerId: event.pointerId };
    renderAnnotationDraft(annotationTool, start, start);
    try { annotationLayer.setPointerCapture(event.pointerId); } catch (_) {}
  });
  annotationLayer.addEventListener('pointermove', (event) => {
    if (!annotationDrag || event.pointerId !== annotationDrag.pointerId) return;
    renderAnnotationDraft(annotationDrag.type, annotationDrag.start, annotationPoint(event));
  });
  annotationLayer.addEventListener('pointerup', (event) => {
    if (!annotationDrag || event.pointerId !== annotationDrag.pointerId) return;
    const drag = annotationDrag; const end = annotationPoint(event);
    annotationDraftElement?.remove(); annotationDraftElement = null; annotationDrag = null;
    createAnnotationRecord(drag.type, drag.start, end);
  });
  annotationLayer.addEventListener('pointercancel', (event) => {
    if (!annotationDrag || event.pointerId !== annotationDrag.pointerId) return;
    annotationDraftElement?.remove(); annotationDraftElement = null; annotationDrag = null;
    renderAnnotationShapes();
  });
  $('[data-studio-clear-annotations]').addEventListener('click', () => {
    annotationRecords = []; selectedAnnotationId = null; nextAnnotationNumber = 1; invalidateInstructions(); renderAnnotations(); scheduleDraftSave();
  });

  $('[data-studio-generate-instructions]').addEventListener('click', () => {
    invalidateInstructions();
    if (annotationRecords.length === 0) { setSaveState('請先建立至少一個標註', true); return; }
    const incomplete = annotationRecords.find((record) => record.text.trim() === '');
    if (incomplete) {
      selectAnnotation(incomplete.annotationId, { focus: true, scroll: true });
      setSaveState(`請先填寫標註 ${incomplete.number} 的修改說明`, true);
      return;
    }
    generatedInstructionPayload = buildInstructionPayload();
    generatedInstructionText = buildInstructionText(generatedInstructionPayload);
    $('[data-studio-instruction-output]').value = generatedInstructionText;
    $$('[data-studio-copy-instructions],[data-studio-download-instructions],[data-studio-download-json]').forEach((button) => { button.disabled = false; });
    setSaveState(`已產生 ${annotationRecords.length} 筆編號修改指令`);
  });

  $('[data-studio-copy-instructions]').addEventListener('click', async () => {
    if (!generatedInstructionText) return;
    try {
      await navigator.clipboard.writeText(generatedInstructionText);
      setSaveState('修改指令已複製');
    } catch (error) {
      console.warn('Clipboard copy failed', error);
      setSaveState('瀏覽器拒絕剪貼簿存取，請改用下載 TXT', true);
    }
  });
  $('[data-studio-download-instructions]').addEventListener('click', () => {
    if (!generatedInstructionText) return;
    downloadStudioFile('twwater-html-modification-instructions.txt', generatedInstructionText, 'text/plain;charset=utf-8');
    setSaveState('修改指令 TXT 已下載');
  });
  $('[data-studio-download-json]').addEventListener('click', () => {
    if (!generatedInstructionPayload) return;
    downloadStudioFile('twwater-html-modification-instructions.json', JSON.stringify(generatedInstructionPayload, null, 2), 'application/json;charset=utf-8');
    setSaveState('修改指令 JSON 已下載');
  });

  function inspectElement(event) {
    event.preventDefault(); event.stopPropagation();
    selectedElement = event.target;
    const rectangle = selectedElement.getBoundingClientRect();
    highlight.hidden = false; highlight.style.left = `${rectangle.left}px`; highlight.style.top = `${rectangle.top}px`; highlight.style.width = `${rectangle.width}px`; highlight.style.height = `${rectangle.height}px`;
    $('[data-studio-element-name]').textContent = `<${selectedElement.tagName.toLowerCase()}>${selectedElement.className ? ` · .${String(selectedElement.className).trim().replace(/\s+/g, '.')}` : ''}`;
    $('[data-studio-element-size]').textContent = `${Math.round(rectangle.width)} × ${Math.round(rectangle.height)} px`;
    const input = $('[data-studio-quick-text]'); const editable = selectedElement.children.length === 0;
    input.disabled = !editable; input.value = editable ? selectedElement.textContent : ''; $('[data-studio-apply-text]').disabled = !editable;
  }
  $('[data-studio-apply-text]').addEventListener('click', () => {
    cancelPendingEntryOperation();
    if (!selectedElement || selectedElement.children.length !== 0) return;
    selectedElement.textContent = $('[data-studio-quick-text]').value.slice(0, 500);
    markup = sanitizeMarkup(previewFrame.contentDocument.body.innerHTML); htmlCode.value = markup; saveDraft(); renderAll(); setMode('inspect');
  });

  $('[data-studio-apply-code]').addEventListener('click', () => {
    cancelPendingEntryOperation();
    const proposedMarkup = htmlCode.value.slice(0, maxMarkupLength); const proposedCss = cssCode.value.slice(0, maxCssLength);
    const previousMarkup = markup; const previousCss = css;
    markup = sanitizeMarkup(proposedMarkup); css = proposedCss;
    if (!renderAll()) {
      markup = previousMarkup; css = previousCss; renderAll();
      setSaveState('HTML／CSS 未套用：請移除外部資源網址或修正語法', true);
      return;
    }
    htmlCode.value = markup; saveDraft(); setMode('preview');
  });

  $('#studio-download-html').addEventListener('click', () => {
    const safeMarkup = sanitizeMarkup(markup);
    const safeCss = sanitizeCss(css);
    const output = `<!doctype html>\n<html lang="zh-Hant">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n<meta http-equiv="Content-Security-Policy" content="default-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'; script-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'">\n<title>HTML 工作室輸出</title>\n<style>${safeCss.replace(/<\/style/gi, '<\\/style')}</style>\n</head>\n<body>\n${safeMarkup}\n</body>\n</html>\n`;
    const blob = new Blob([output], { type: 'text/html;charset=utf-8' }); const url = URL.createObjectURL(blob); const anchor = document.createElement('a');
    anchor.href = url; anchor.download = 'twwater-html-studio.html'; document.body.append(anchor); anchor.click(); anchor.remove(); setTimeout(() => URL.revokeObjectURL(url), 0); setSaveState('HTML 已下載');
  });

  $('[data-studio-reset]').addEventListener('click', () => {
    if (!window.confirm('確定回到初始範例？瀏覽器草稿與目前大圖會重設。')) return;
    cancelPendingEntryOperation();
    localStorage.removeItem(storageKey); markup = initialMarkup; css = initialCss; htmlCode.value = markup; cssCode.value = css; intentInput.value = '建立一個近滿版、字體清楚的供水監測入口，包含狀態摘要、測站表格與主要操作。';
    drawHistory = []; clearCanvasSelection(); drawInitialSource(); htmlGenerated = false; studioEntryKind = 'image'; generatedSource = ''; restoredSourceImage = ''; restoredGeneratedSource = false; restoredEntryKind = 'image'; restoredAnnotations = []; annotationNormalizationPending = false; annotationRecords = []; selectedAnnotationId = null; nextAnnotationNumber = 1; invalidateInstructions(); renderAnnotations(); updateGeneratedAvailability(); renderAll(); setMode('source'); updateSteps(0); setSaveState('已回到初始範例');
  });

  window.addEventListener('resize', () => { if (mode === 'compare') scaleCompare(); if (canvasSelection) updateCanvasSelection(); if (mode === 'annotate') renderAnnotationShapes(); });
  htmlCode.addEventListener('input', cancelPendingEntryOperation);
  cssCode.addEventListener('input', cancelPendingEntryOperation);
  intentInput.addEventListener('change', () => { invalidateInstructions(); saveDraft(); });
  restoreDraft(); drawInitialSource(); const sourceRestoreStarted = restoreSourceCanvas(); restoreAnnotationRecords(restoredAnnotations); if (annotationNormalizationPending && !sourceRestoreStarted) { annotationNormalizationPending = false; saveDraft(); setSaveState('已還原並正規化瀏覽器草稿'); } renderAll(); setMode('source'); updateSteps(0);
})();
