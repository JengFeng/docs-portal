(() => {
  'use strict';
  const root = document.querySelector('[data-studio-root]');
  if (!root) return;

  const $ = (selector, scope = root) => scope.querySelector(selector);
  const $$ = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));
  const storageKey = 'twwater-html-studio-draft-v1';
  const maxMarkupLength = 250000;
  const maxCssLength = 250000;
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
  let annotationCount = 0;
  let generatedSource = '';
  let canvasSelection = null;
  let selectionAction = null;
  let draftSaveTimer = null;
  let restoredSourceImage = '';
  let restoredGeneratedSource = false;
  let restoredAnnotations = [];

  function setSaveState(text, isError = false) {
    saveState.textContent = text;
    saveState.classList.toggle('is-error', isError);
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

  function serializeAnnotations() {
    return $$('.studio-annotation-box', annotationLayer).map((box) => {
      try {
        const geometry = JSON.parse(box.dataset.geometry || '{}');
        return {
          x: Math.max(0, Math.min(1, Number(geometry.x) || 0)),
          y: Math.max(0, Math.min(1, Number(geometry.y) || 0)),
          width: Math.max(0, Math.min(1, Number(geometry.width) || 0)),
          height: Math.max(0, Math.min(1, Number(geometry.height) || 0)),
          label: String(box.dataset.label || '新標註').slice(0, 36)
        };
      } catch (_) { return null; }
    }).filter((record) => record && record.width > 0 && record.height > 0).slice(0, 100);
  }

  function restoreAnnotationRecords(records) {
    annotationLayer.replaceChildren();
    records.slice(0, 100).forEach((record) => {
      const box = document.createElement('div');
      box.className = 'studio-annotation-box'; box.dataset.label = String(record.label || '新標註').slice(0, 36);
      const geometry = { x: Number(record.x), y: Number(record.y), width: Number(record.width), height: Number(record.height) };
      if (Object.values(geometry).some((value) => !Number.isFinite(value) || value < 0 || value > 1) || geometry.width <= 0 || geometry.height <= 0) return;
      box.dataset.geometry = JSON.stringify(geometry);
      box.style.left = `${geometry.x * 100}%`; box.style.top = `${geometry.y * 100}%`;
      box.style.width = `${geometry.width * 100}%`; box.style.height = `${geometry.height * 100}%`;
      annotationLayer.append(box);
    });
    annotationCount = annotationLayer.children.length;
    $('[data-studio-annotation-count]').textContent = `${annotationCount} 個標註`;
  }

  function saveDraft() {
    try {
      const sourceImage = canvas.toDataURL('image/webp', 0.92);
      if (sourceImage.length > 3500000) throw new Error('Source image exceeds browser draft limit.');
      const payload = JSON.stringify({ version: 2, markup, css, intent: intentInput.value.slice(0, 4000), sourceImage, hasGeneratedSource: generatedSource !== '', annotations: serializeAnnotations() });
      localStorage.setItem(storageKey, payload);
      setSaveState('瀏覽器草稿已儲存（含起點大圖）');
    } catch (error) {
      console.warn('HTML studio draft was not stored', error);
      setSaveState('瀏覽器草稿未儲存', true);
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
    if (!restoredSourceImage) return;
    const image = new Image();
    image.onload = () => {
      context.clearRect(0, 0, canvas.width, canvas.height);
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      generatedSource = restoredGeneratedSource ? restoredSourceImage : '';
      if (generatedSource) sourceCompare.src = generatedSource;
      setSaveState('已還原瀏覽器草稿（含起點大圖）');
    };
    image.onerror = () => { localStorage.removeItem(storageKey); setSaveState('起點大圖草稿無法還原', true); };
    image.src = restoredSourceImage;
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
    generatedSource = '';
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
        generatedSource = ''; scheduleDraftSave();
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
      generatedSource = ''; scheduleDraftSave();
    } else {
      generatedSource = ''; scheduleDraftSave();
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
  $('#studio-draw-undo').addEventListener('click', () => { const state = drawHistory.pop(); if (state) { context.putImageData(state, 0, 0); clearCanvasSelection(); generatedSource = ''; scheduleDraftSave(); } });
  $('#studio-draw-clear').addEventListener('click', () => { saveDrawState(); context.fillStyle = '#fff'; context.fillRect(0, 0, 1200, 675); clearCanvasSelection(); generatedSource = ''; scheduleDraftSave(); });
  $('#studio-image-upload').addEventListener('change', async (event) => {
    const file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 8 * 1024 * 1024) { setSaveState('圖片必須是 PNG／JPEG／WebP，且不超過 8 MiB', true); return; }
    try {
      const image = await createImageBitmap(file);
      if (image.width > 4096 || image.height > 4096) { image.close(); setSaveState('圖片尺寸不可超過 4096 × 4096', true); return; }
      saveDrawState(); context.fillStyle = '#fff'; context.fillRect(0, 0, 1200, 675); clearCanvasSelection();
      const scale = Math.min(1200 / image.width, 675 / image.height); const width = image.width * scale; const height = image.height * scale;
      context.drawImage(image, (1200 - width) / 2, (675 - height) / 2, width, height); image.close(); generatedSource = ''; scheduleDraftSave(); setSaveState('圖片已放入起點大圖');
    } catch (error) { console.warn('HTML studio image decode failed', error); setSaveState('圖片無法解碼', true); }
  });

  function updateSteps(activeIndex) {
    $$('.studio-steps li').forEach((item, index) => { item.classList.toggle('is-active', index === activeIndex); item.classList.toggle('is-done', index < activeIndex); });
  }

  function setMode(nextMode) {
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
  }

  $$('[data-studio-mode]').forEach((button) => button.addEventListener('click', () => setMode(button.dataset.studioMode)));
  $$('[data-studio-go]').forEach((button) => button.addEventListener('click', () => setMode(button.dataset.studioGo)));
  $$('[data-studio-device]').forEach((button) => button.addEventListener('click', () => {
    $$('[data-studio-device]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
    previewShell.classList.remove('is-tablet', 'is-mobile');
    if (button.dataset.studioDevice !== 'desktop') previewShell.classList.add(`is-${button.dataset.studioDevice}`);
    $('[data-studio-device-label]').textContent = button.dataset.studioDevice === 'desktop' ? 'DESKTOP' : button.dataset.studioDevice === 'tablet' ? 'TABLET · 768' : 'MOBILE · 390';
  }));

  $('#studio-generate-html').addEventListener('click', () => { generatedSource = canvas.toDataURL('image/png'); sourceCompare.src = generatedSource; renderAll(); setMode('compare'); saveDraft(); });

  function annotationPoint(event) {
    const rectangle = annotationLayer.getBoundingClientRect();
    return { x: event.clientX - rectangle.left, y: event.clientY - rectangle.top };
  }
  annotationLayer.addEventListener('pointerdown', (event) => {
    if (event.target !== annotationLayer) return;
    const point = annotationPoint(event); const box = document.createElement('div'); box.className = 'studio-annotation-box'; box.dataset.label = '新標註'; box.style.left = `${point.x}px`; box.style.top = `${point.y}px`; annotationLayer.append(box);
    annotationDrag = { start: point, box };
    try { annotationLayer.setPointerCapture(event.pointerId); } catch (_) {}
  });
  annotationLayer.addEventListener('pointermove', (event) => {
    if (!annotationDrag) return;
    const point = annotationPoint(event); const start = annotationDrag.start;
    annotationDrag.box.style.left = `${Math.min(start.x, point.x)}px`; annotationDrag.box.style.top = `${Math.min(start.y, point.y)}px`;
    annotationDrag.box.style.width = `${Math.abs(point.x - start.x)}px`; annotationDrag.box.style.height = `${Math.abs(point.y - start.y)}px`;
  });
  annotationLayer.addEventListener('pointerup', () => {
    if (!annotationDrag) return;
    const box = annotationDrag.box;
    const width = parseFloat(box.style.width) || 0; const height = parseFloat(box.style.height) || 0;
    if (width < 8 || height < 8 || annotationLayer.clientWidth <= 0 || annotationLayer.clientHeight <= 0) {
      box.remove(); annotationDrag = null; return;
    }
    const geometry = {
      x: (parseFloat(box.style.left) || 0) / annotationLayer.clientWidth,
      y: (parseFloat(box.style.top) || 0) / annotationLayer.clientHeight,
      width: width / annotationLayer.clientWidth,
      height: height / annotationLayer.clientHeight
    };
    box.dataset.geometry = JSON.stringify(geometry);
    box.style.left = `${geometry.x * 100}%`; box.style.top = `${geometry.y * 100}%`;
    box.style.width = `${geometry.width * 100}%`; box.style.height = `${geometry.height * 100}%`;
    annotationCount += 1; $('[data-studio-annotation-count]').textContent = `${annotationCount} 個標註`; $('[data-studio-annotation-text]').focus(); annotationDrag = null; scheduleDraftSave();
  });
  $('[data-studio-save-annotation]').addEventListener('click', () => { const box = annotationLayer.querySelector('.studio-annotation-box:last-child'); const input = $('[data-studio-annotation-text]'); if (box && input.value.trim()) { box.dataset.label = input.value.trim().slice(0, 36); input.value = ''; scheduleDraftSave(); } });
  $('[data-studio-clear-annotations]').addEventListener('click', () => { annotationLayer.replaceChildren(); annotationCount = 0; $('[data-studio-annotation-count]').textContent = '0 個標註'; scheduleDraftSave(); });

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
    if (!selectedElement || selectedElement.children.length !== 0) return;
    selectedElement.textContent = $('[data-studio-quick-text]').value.slice(0, 500);
    markup = sanitizeMarkup(previewFrame.contentDocument.body.innerHTML); htmlCode.value = markup; saveDraft(); renderAll(); setMode('inspect');
  });

  $('[data-studio-apply-code]').addEventListener('click', () => {
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
    localStorage.removeItem(storageKey); markup = initialMarkup; css = initialCss; htmlCode.value = markup; cssCode.value = css; intentInput.value = '建立一個近滿版、字體清楚的供水監測入口，包含狀態摘要、測站表格與主要操作。';
    drawHistory = []; clearCanvasSelection(); drawInitialSource(); generatedSource = ''; restoredSourceImage = ''; restoredGeneratedSource = false; restoredAnnotations = []; annotationLayer.replaceChildren(); annotationCount = 0; $('[data-studio-annotation-count]').textContent = '0 個標註'; renderAll(); setMode('source'); updateSteps(0); setSaveState('已回到初始範例');
  });

  window.addEventListener('resize', () => { if (mode === 'compare') scaleCompare(); if (canvasSelection) updateCanvasSelection(); });
  intentInput.addEventListener('change', saveDraft);
  restoreDraft(); drawInitialSource(); restoreSourceCanvas(); restoreAnnotationRecords(restoredAnnotations); renderAll(); setMode('source'); updateSteps(0);
})();
