(function () {
  'use strict';

  var ROOT_SELECTOR = '[data-presentation-review], #presentation-review-app';
  var ALLOWED_KINDS = ['rectangle', 'arrow', 'highlight', 'text', 'number'];
  var KIND_LABELS = {
    rectangle: '矩形框選',
    arrow: '箭頭',
    highlight: '螢光標示',
    text: '文字標注',
    number: '編號標注'
  };
  var HISTORY_LIMIT = 100;
  var MAX_NOTE_LENGTH = 1000;
  var MAX_TEXT_LENGTH = 500;

  function clamp(value, minimum, maximum) {
    var number = Number(value);
    if (!Number.isFinite(number)) return minimum;
    return Math.min(maximum, Math.max(minimum, number));
  }

  function toInteger(value, fallback) {
    var number = Number.parseInt(String(value), 10);
    return Number.isFinite(number) ? number : fallback;
  }

  function boundedText(value, maximum) {
    return String(value == null ? '' : value).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '').slice(0, maximum);
  }

  function newClientId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'annotation-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function cloneJson(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function bytesToHex(buffer) {
    return Array.from(new Uint8Array(buffer)).map(function (value) {
      return value.toString(16).padStart(2, '0');
    }).join('');
  }

  function appendChildren(parent, children) {
    (children || []).forEach(function (child) {
      if (child == null) return;
      parent.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return parent;
  }

  function element(tagName, attributes, children) {
    var node = document.createElement(tagName);
    Object.keys(attributes || {}).forEach(function (name) {
      var value = attributes[name];
      if (value == null || value === false) return;
      if (name === 'className') {
        node.className = value;
      } else if (name === 'text') {
        node.textContent = value;
      } else if (name === 'dataset') {
        Object.keys(value).forEach(function (key) { node.dataset[key] = value[key]; });
      } else if (name === 'checked' || name === 'disabled' || name === 'hidden') {
        node[name] = Boolean(value);
      } else {
        node.setAttribute(name, value);
      }
    });
    return appendChildren(node, children);
  }

  function makeButton(label, action, title, options) {
    var settings = options || {};
    var button = element('button', {
      type: 'button',
      className: 'presentation-tool-button' + (settings.className ? ' ' + settings.className : ''),
      title: title || label,
      dataset: { reviewAction: action },
      text: label
    });
    if (settings.pressed != null) button.setAttribute('aria-pressed', settings.pressed ? 'true' : 'false');
    return button;
  }

  function getSameOriginUrl(rawUrl, label) {
    if (!rawUrl) throw new Error(label + '尚未設定。');
    var parsed;
    try {
      parsed = new URL(String(rawUrl), window.location.href);
    } catch (error) {
      throw new Error(label + '格式不正確。');
    }
    if (parsed.origin !== window.location.origin) {
      throw new Error(label + '必須使用本站網址。');
    }
    return parsed.href;
  }

  function apiMessage(payload, fallback) {
    if (payload && typeof payload.message === 'string') {
      return boundedText(payload.message, 300) || fallback;
    }
    if (payload && typeof payload.error === 'string') {
      return boundedText(payload.error, 300) || fallback;
    }
    return fallback;
  }

  function ApiError(message, status, payload) {
    this.name = 'ApiError';
    this.message = message;
    this.status = status;
    this.payload = payload;
  }
  ApiError.prototype = Object.create(Error.prototype);

  async function fetchJson(url, options) {
    var response = await window.fetch(url, options);
    var type = response.headers.get('content-type') || '';
    var payload = null;
    if (type.indexOf('application/json') !== -1) {
      try {
        payload = await response.json();
      } catch (error) {
        payload = null;
      }
    }
    if (!response.ok) {
      throw new ApiError(apiMessage(payload, '伺服器回應失敗（HTTP ' + response.status + '）。'), response.status, payload);
    }
    if (payload === null) throw new ApiError('伺服器未回傳有效的 JSON。', response.status, null);
    return payload;
  }

  function normalizeStyle(style) {
    var source = style && typeof style === 'object' ? style : {};
    var rawStrokeWidth = source.stroke_width != null ? source.stroke_width : source.strokeWidth;
    var rawFontSize = source.font_size != null ? source.font_size : source.fontSize;
    var normalizedFontSize = Number(rawFontSize == null ? .036 : rawFontSize);
    if (normalizedFontSize > 1) normalizedFontSize /= 900;
    return {
      stroke: /^#[0-9a-f]{6}$/i.test(String(source.stroke || '')) ? String(source.stroke) : '#d83b3b',
      fill: /^#[0-9a-f]{6}$/i.test(String(source.fill || '')) ? String(source.fill) : '#ffd84d',
      opacity: clamp(source.opacity == null ? 1 : source.opacity, .05, 1),
      stroke_width: clamp(rawStrokeWidth == null ? .003 : (Number(rawStrokeWidth) > 1 ? Number(rawStrokeWidth) / 1000 : rawStrokeWidth), .001, .03),
      font_size: clamp(normalizedFontSize, .012, .15)
    };
  }

  function normalizeAnnotation(raw, defaultVersionId) {
    var source = raw && typeof raw === 'object' ? raw : {};
    var geometry = source.geometry && typeof source.geometry === 'object' ? source.geometry : source;
    var kind = String(source.kind || source.annotation_type || source.type || '').toLowerCase();
    if (kind === 'rect' || kind === 'box') kind = 'rectangle';
    if (kind === 'highlighter') kind = 'highlight';
    if (kind === 'marker') kind = 'number';
    if (ALLOWED_KINDS.indexOf(kind) === -1) return null;
    var hasSourceText = Object.prototype.hasOwnProperty.call(source, 'text');
    var hasGeometryText = Object.prototype.hasOwnProperty.call(geometry, 'text');
    var fallbackText = source.note != null ? source.note : (source.comment != null ? source.comment : source.comment_text);
    var rawText = hasSourceText
      ? source.text
      : (hasGeometryText ? geometry.text : (source.label != null ? source.label : (kind === 'text' ? fallbackText || '' : '')));
    var page = toInteger(source.slide_number != null ? source.slide_number : source.page_number, 1);
    var clientId = boundedText(source.client_id || source.clientId || '', 100) || newClientId();
    var serverId = source.annotation_id != null ? source.annotation_id : (source.id != null ? source.id : null);
    var comment = boundedText(source.note || source.comment || source.comment_text || '', MAX_NOTE_LENGTH);
    var markerMatch = kind === 'number' ? comment.match(/(?:編號|#)\s*(\d{1,4})/i) : null;
    var annotation = {
      annotation_id: serverId,
      row_version: boundedText(source.row_version || '', 256),
      client_id: clientId,
      version_id: source.version_id != null ? source.version_id : defaultVersionId,
      slide_number: Math.max(1, page),
      kind: kind,
      x: clamp(geometry.x, 0, 1),
      y: clamp(geometry.y, 0, 1),
      width: clamp(geometry.width == null ? .08 : geometry.width, .001, 1),
      height: clamp(geometry.height == null ? .05 : geometry.height, .001, 1),
      x2: clamp(geometry.x2 == null ? Number(geometry.x || 0) + Number(geometry.width || .08) : geometry.x2, 0, 1),
      y2: clamp(geometry.y2 == null ? Number(geometry.y || 0) + Number(geometry.height || 0) : geometry.y2, 0, 1),
      text: boundedText(rawText, MAX_TEXT_LENGTH),
      number: Math.max(1, toInteger(source.number != null ? source.number : geometry.number, markerMatch ? markerMatch[1] : 1)),
      note: comment,
      included: source.included !== false,
      style: normalizeStyle(source.style),
      slide_asset_hash: boundedText(source.slide_asset_hash || '', 128)
    };
    if (kind === 'arrow' && Array.isArray(geometry.points) && geometry.points.length >= 2) {
      annotation.x = clamp(geometry.points[0].x, 0, 1);
      annotation.y = clamp(geometry.points[0].y, 0, 1);
      annotation.x2 = clamp(geometry.points[geometry.points.length - 1].x, 0, 1);
      annotation.y2 = clamp(geometry.points[geometry.points.length - 1].y, 0, 1);
      annotation.width = Math.abs(annotation.x2 - annotation.x);
      annotation.height = Math.abs(annotation.y2 - annotation.y);
    }
    return annotation;
  }

  function PresentationReview(root) {
    this.root = root;
    this.pptx = window.TWWaterPptx || null;
    this.markdown = window.TWWaterMarkdown || null;
    this.jszip = window.JSZip || null;
    this.fabric = window.fabric || null;
    this.manifest = null;
    this.documentInfo = {};
    this.documentFormat = 'pptx';
    this.versionInfo = {};
    this.renditionInfo = {};
    this.slides = [];
    this.pdfDocument = null;
    this.fabricCanvas = null;
    this.annotations = [];
    this.deletedAnnotationIds = [];
    this.pageNumber = 1;
    this.zoom = 1;
    this.currentTool = 'select';
    this.renderSequence = 0;
    this.renderTask = null;
    this.thumbnailObserver = null;
    this.thumbnailNodes = new Map();
    this.history = [];
    this.historyIndex = -1;
    this.savedSnapshot = '';
    this.restoreInProgress = false;
    this.drawing = null;
    this.historyTimer = null;
    this.resizeTimer = null;
    this.lastNumber = 0;
    this.lastCreatedRequestExportUrl = null;
    this.nodes = {};
    this.permissions = { annotate: false, create_request: false };
    this.urls = {};
    this.csrfToken = boundedText(root.dataset.csrfToken || '', 256);
    this.manifestRequest = null;
    this.lifecycleBound = false;
    this.initStarted = false;
    this.viewerInitializing = false;
    this.viewerInitialized = false;
    this.isDisposed = false;
  }

  PresentationReview.prototype.init = async function () {
    if (this.initStarted || this.viewerInitializing || this.viewerInitialized) return;
    this.initStarted = true;
    this.root.classList.add('presentation-review');
    this.root.setAttribute('aria-busy', 'true');
    this.root.setAttribute('tabindex', '-1');
    try {
      this.buildShell();
      this.bindLifecycleEvents();
      this.urls.manifest = getSameOriginUrl(this.root.dataset.manifestUrl, '文件審閱資訊網址');
      if (this.root.dataset.annotationSaveUrl) this.urls.save = getSameOriginUrl(this.root.dataset.annotationSaveUrl, '標注儲存網址');
      if (this.root.dataset.annotationDeleteUrl) this.urls.remove = getSameOriginUrl(this.root.dataset.annotationDeleteUrl, '標注刪除網址');
      if (this.root.dataset.requestCreateUrl) this.urls.request = getSameOriginUrl(this.root.dataset.requestCreateUrl, '修改需求網址');
      this.setMessage('正在載入文件預覽…', 'warning');
      var previewState = await this.loadManifest();
      if (this.isDisposed || !previewState || !previewState.ready) return;
      if (this.nodes.shell) this.nodes.shell.hidden = false;
      await this.loadDependencies();
      this.assertDependencies();
      this.viewerInitializing = true;
      await this.loadSourceDocument();
      this.configurePermissions();
      this.buildThumbnails();
      this.createFabricCanvas();
      this.bindEvents();
      this.recordHistory(true);
      this.savedSnapshot = this.capturePersistedSnapshot();
      await this.showPage(1, false);
      this.nodes.fallback.hidden = true;
      if (this.permissions.annotate) this.setMessage('', '');
      this.updateDirtyState();
      this.viewerInitialized = true;
      this.viewerInitializing = false;
      this.root.removeAttribute('aria-busy');
    } catch (error) {
      this.viewerInitializing = false;
      if (!this.isDisposed) this.fail(error);
    }
  };

  PresentationReview.prototype.bindLifecycleEvents = function () {
    if (this.lifecycleBound) return;
    this.lifecycleBound = true;
    var self = this;
    window.addEventListener('pagehide', function () {
      self.isDisposed = true;
    });
    window.addEventListener('beforeunload', function (event) {
      self.isDisposed = true;
      if (!self.viewerInitialized || !self.isDirty()) return;
      event.preventDefault();
      event.returnValue = '';
    });
  };

  PresentationReview.prototype.loadDependencies = async function () {
    this.pptx = window.TWWaterPptx || this.pptx;
    this.markdown = window.TWWaterMarkdown || this.markdown;
    this.jszip = window.JSZip || this.jszip;
    if (!this.fabric || typeof this.fabric.Canvas !== 'function') {
      try {
        var fabricModuleUrl = getSameOriginUrl(
          this.root.dataset.fabricModuleUrl || 'assets/vendor/fabric/index.min.mjs',
          'Fabric.js 模組網址'
        );
        var fabricModule = await import(fabricModuleUrl);
        var candidates = [fabricModule, fabricModule.fabric, fabricModule.default];
        this.fabric = candidates.find(function (candidate) {
          return candidate && typeof candidate.Canvas === 'function';
        }) || null;
      } catch (error) {
        this.fabric = null;
      }
    }
  };

  PresentationReview.prototype.assertDependencies = function () {
    var missing = [];
    if (this.documentFormat === 'md') {
      if (!this.markdown || !this.markdown.MarkdownDocument || typeof this.markdown.MarkdownDocument.load !== 'function') missing.push('Markdown Renderer');
    } else {
      if (!this.jszip || typeof this.jszip.loadAsync !== 'function') missing.push('JSZip');
      if (!this.pptx || !this.pptx.PptxDocument || typeof this.pptx.PptxDocument.load !== 'function') missing.push('PPTX SVG Renderer');
    }
    var fabricReady = this.fabric
      && typeof this.fabric.Canvas === 'function'
      && typeof this.fabric.Rect === 'function'
      && typeof this.fabric.Line === 'function'
      && typeof this.fabric.Triangle === 'function'
      && typeof this.fabric.Group === 'function'
      && typeof this.fabric.Circle === 'function'
      && typeof this.fabric.Text === 'function'
      && (typeof this.fabric.IText === 'function' || typeof this.fabric.Textbox === 'function');
    if (!fabricReady) missing.push('Fabric.js');
    if (missing.length) {
      throw new Error('文件檢視元件尚未安裝完成：缺少 ' + missing.join('、') + '。仍可使用原始檔下載；請通知管理員安裝站內版本。');
    }
  };

  PresentationReview.prototype.buildShell = function () {
    var existingFallback = this.root.querySelector('[data-presentation-fallback], .presentation-loading');
    this.nodes.fallback = existingFallback || element('div', {
      className: 'presentation-review-fallback',
      dataset: { presentationFallback: '' },
      text: '此文件目前可下載原始檔；線上檢視功能載入後會顯示於此。'
    });
    if (!existingFallback) this.root.appendChild(this.nodes.fallback);

    this.nodes.message = element('p', {
      className: 'presentation-review-message',
      role: 'status',
      'aria-live': 'polite'
    });

    this.nodes.pageNumber = element('input', { type: 'number', min: '1', value: '1', dataset: { pageNumber: '' }, 'aria-label': '目前頁次' });
    this.nodes.pageTotal = element('span', { dataset: { pageTotal: '' }, text: '—' });
    this.nodes.zoomLabel = element('span', { className: 'presentation-zoom-label', dataset: { zoomLabel: '' }, text: '100%' });

    var navigation = element('div', { className: 'presentation-toolbar-group' }, [
      makeButton('上一頁', 'previous-page', '上一張投影片'),
      element('label', { className: 'presentation-page-control' }, [
        element('span', { className: 'sr-only', text: '目前頁次' }),
        this.nodes.pageNumber,
        element('span', { text: '／' }),
        this.nodes.pageTotal
      ]),
      makeButton('下一頁', 'next-page', '下一張投影片')
    ]);

    var zoom = element('div', { className: 'presentation-toolbar-group' }, [
      makeButton('－', 'zoom-out', '縮小'),
      this.nodes.zoomLabel,
      makeButton('＋', 'zoom-in', '放大'),
      makeButton('符合寬度', 'zoom-reset', '重設為符合寬度')
    ]);

    var tools = element('div', { className: 'presentation-toolbar-group', role: 'toolbar', 'aria-label': '標注工具' });
    [
      ['select', '選取', '選取、移動或調整標注'],
      ['rectangle', '框選', '繪製矩形框'],
      ['arrow', '箭頭', '繪製指示箭頭'],
      ['highlight', '螢光', '繪製半透明螢光區域'],
      ['text', '文字', '新增文字標注'],
      ['number', '編號', '新增編號標注']
    ].forEach(function (entry) {
      var button = makeButton(entry[1], 'tool-' + entry[0], entry[2], { pressed: entry[0] === 'select' });
      button.dataset.annotationTool = entry[0];
      tools.appendChild(button);
    });

    var editing = element('div', { className: 'presentation-toolbar-group' }, [
      makeButton('復原', 'undo', '復原上一個標注操作'),
      makeButton('重做', 'redo', '重做標注操作'),
      makeButton('刪除', 'delete-selection', '刪除選取的標注', { className: 'is-danger' })
    ]);

    this.nodes.toolbar = element('div', { className: 'presentation-toolbar', 'aria-label': '簡報檢視工具列' }, [navigation, zoom, tools, editing]);
    this.nodes.thumbnailList = element('nav', { className: 'presentation-thumbnails', 'aria-label': '投影片縮圖' });
    this.nodes.pdfCanvas = element('canvas', { className: 'presentation-pdf-canvas presentation-pptx-canvas', dataset: { pdfCanvas: '' }, 'aria-label': '瀏覽器解析的投影片內容' });
    this.nodes.annotationCanvas = element('canvas', { className: 'presentation-annotation-canvas', dataset: { annotationCanvas: '' }, 'aria-label': '投影片標注層' });
    this.nodes.canvasStage = element('div', { className: 'presentation-canvas-stage' }, [this.nodes.pdfCanvas, this.nodes.annotationCanvas]);
    this.nodes.loading = element('div', { className: 'presentation-loading-overlay', text: '正在載入投影片…' });
    this.nodes.stageScroll = element('div', { className: 'presentation-stage-scroll' }, [this.nodes.canvasStage, this.nodes.loading]);

    this.nodes.requestInstruction = element('textarea', {
      maxlength: String(MAX_NOTE_LENGTH),
      placeholder: '例如：請維持既有版型，將勾選區域的數據更新，並提高標題對比。',
      dataset: { requestInstruction: '' }
    });
    this.nodes.annotationList = element('ol', { className: 'presentation-annotation-list', dataset: { annotationList: '' } });
    this.nodes.copyRequest = element('button', { type: 'button', className: 'secondary-button', dataset: { reviewAction: 'copy-request' }, text: '複製 Discord 指令' });
    this.nodes.downloadRequest = element('button', { type: 'button', className: 'secondary-button', dataset: { reviewAction: 'download-request' }, text: '下載需求 .md' });

    this.nodes.downloadAnnotatedSlide = element('button', { type: 'button', className: 'secondary-button', dataset: { reviewAction: 'download-annotated-slide' }, text: '下載本頁標注圖' });
    this.nodes.createRequest = element('button', { type: 'button', className: 'primary-button', dataset: { reviewAction: 'create-request' }, text: '建立修改需求' });
    this.nodes.save = element('button', { type: 'button', className: 'primary-button', dataset: { reviewAction: 'save-annotations' }, text: '儲存標注' });
    this.nodes.dirty = element('span', { className: 'presentation-dirty-indicator', dataset: { dirtyIndicator: '' }, text: '尚無未儲存變更' });

    this.nodes.reviewPanel = element('aside', { className: 'presentation-review-panel', 'aria-label': '標注與修改需求' }, [
      element('div', {}, [
        element('p', { className: 'eyebrow', text: 'PRESENTATION REVIEW' }),
        element('h2', { text: '標注與修改需求' }),
        element('p', { text: '勾選要納入需求的標注，補充說明後即可複製或匯出 Discord 指令。' })
      ]),
      element('label', { className: 'presentation-request-field' }, [
        element('span', { text: '整體修改說明' }),
        this.nodes.requestInstruction
      ]),
      element('div', {}, [element('h3', { text: '標注項目' }), this.nodes.annotationList]),
      element('div', { className: 'presentation-request-actions' }, [this.nodes.copyRequest, this.nodes.downloadRequest, this.nodes.downloadAnnotatedSlide, this.nodes.createRequest]),
      element('div', { className: 'presentation-save-row' }, [this.nodes.dirty, this.nodes.save]),
      element('div', { className: 'presentation-keyboard-help', text: '快捷鍵：Delete 刪除；Ctrl+Z 復原；Ctrl+Y 或 Ctrl+Shift+Z 重做。標注只會寫入獨立審閱層，不會直接改動原始檔。' })
    ]);

    this.nodes.workspace = element('div', { className: 'presentation-workspace' }, [this.nodes.thumbnailList, this.nodes.stageScroll, this.nodes.reviewPanel]);
    this.nodes.shell = element('div', { className: 'presentation-review-shell', dataset: { presentationShell: '' } }, [this.nodes.toolbar, this.nodes.workspace]);
    this.root.appendChild(this.nodes.message);
    this.root.appendChild(this.nodes.shell);
  };

  PresentationReview.prototype.setMessage = function (message, kind) {
    if (!this.nodes.message) return;
    this.nodes.message.textContent = boundedText(message, 500);
    this.nodes.message.className = 'presentation-review-message';
    if (message) {
      this.nodes.message.classList.add('is-visible');
      if (kind) this.nodes.message.classList.add('is-' + kind);
    }
  };

  PresentationReview.prototype.fail = function (error) {
    var message = error && error.message ? error.message : '文件檢視功能無法載入。';
    if (!this.nodes.message) {
      this.nodes.message = element('p', { className: 'presentation-review-message', role: 'alert' });
      this.root.appendChild(this.nodes.message);
    }
    this.setMessage(message, 'error');
    if (this.nodes.fallback) this.nodes.fallback.hidden = false;
    if (this.nodes.fallback && this.nodes.fallback.classList.contains('presentation-loading')) {
      this.nodes.fallback.textContent = '線上預覽目前未就緒；仍可返回文件資訊頁下載原始檔。';
    }
    if (this.nodes.shell) this.nodes.shell.hidden = true;
    this.root.removeAttribute('aria-busy');
  };

  PresentationReview.prototype.loadManifest = async function (signal) {
    if (this.manifestRequest) return this.manifestRequest;
    var self = this;
    var request = fetchJson(this.urls.manifest, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      signal: signal || undefined
    }).then(function (payload) {
      return self.applyManifest(payload);
    });
    this.manifestRequest = request;
    try {
      return await request;
    } finally {
      if (this.manifestRequest === request) this.manifestRequest = null;
    }
  };

  PresentationReview.prototype.applyManifest = function (payload) {
    this.manifest = payload;
    this.documentInfo = payload.document && typeof payload.document === 'object' ? payload.document : {};
    this.documentFormat = boundedText(this.documentInfo.extension || payload.extension || 'pptx', 16).toLowerCase();
    if (['pptx', 'md'].indexOf(this.documentFormat) === -1) throw new Error('此檔案格式不支援線上標注。');
    this.versionInfo = payload.version && typeof payload.version === 'object' ? payload.version : {};
    this.slides = Array.isArray(payload.slides) ? payload.slides.slice() : [];
    var permissions = payload.permissions && typeof payload.permissions === 'object' ? payload.permissions : {};
    this.permissions.annotate = permissions.annotate === true || permissions.can_annotate === true;
    this.permissions.create_request = permissions.create_request === true || permissions.can_create_request === true;

    var manifestDocumentId = this.documentInfo.id || this.documentInfo.public_id || payload.document_id || '';
    var expectedDocumentId = this.root.dataset.documentId || '';
    if (!manifestDocumentId) throw new Error('文件審閱資訊缺少 document.id。');
    if (expectedDocumentId && String(manifestDocumentId).toLowerCase() !== String(expectedDocumentId).toLowerCase()) {
      throw new Error('審閱資訊與目前文件不一致，已停止載入。');
    }
    this.documentInfo.id = String(manifestDocumentId);
    this.versionInfo.id = this.versionInfo.id || this.versionInfo.version_id || payload.version_id || '';
    this.versionInfo.content_hash = boundedText(this.versionInfo.content_hash || this.versionInfo.source_hash || this.documentInfo.content_hash || payload.content_hash || '', 128);
    this.versionHashMissing = !this.versionInfo.id || !this.versionInfo.content_hash;
    if (this.versionHashMissing) {
      this.permissions.annotate = false;
      this.permissions.create_request = false;
    }

    var rendition = payload.rendition && typeof payload.rendition === 'object' ? payload.rendition : {};
    this.renditionInfo = rendition;
    var preview = payload.preview && typeof payload.preview === 'object' ? payload.preview : {};
    var sourceUrl = this.documentInfo.source_url || payload.source_url || this.root.dataset.sourceUrl;
    if (!sourceUrl) {
      this.urls.source = '';
      return {
        ready: false,
        status: boundedText(preview.status || 'source_unavailable', 32).toLowerCase(),
        errorCode: boundedText(preview.error_code || '', 64)
      };
    }
    this.urls.source = getSameOriginUrl(sourceUrl, '文件來源網址');

    this.annotations = (Array.isArray(payload.annotations) ? payload.annotations : []).map(function (entry) {
      return normalizeAnnotation(entry, this.versionInfo.id);
    }, this).filter(Boolean);
    this.lastNumber = this.annotations.reduce(function (maximum, annotation) {
      return annotation.kind === 'number' ? Math.max(maximum, annotation.number) : maximum;
    }, 0);
    return { ready: true, status: 'ready', errorCode: '' };
  };

  PresentationReview.prototype.loadSourceDocument = function () {
    return this.documentFormat === 'md' ? this.loadMarkdown() : this.loadPptx();
  };

  PresentationReview.prototype.loadMarkdown = async function () {
    this.setMessage('正在下載並於瀏覽器安全渲染 Markdown…', 'warning');
    var response = await window.fetch(this.urls.source, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'text/markdown, text/plain;q=0.9' },
      cache: 'no-store'
    });
    if (!response.ok) throw new Error('無法取得原始 Markdown（HTTP ' + response.status + '）。');
    var declaredLength = toInteger(response.headers.get('content-length'), 0);
    var expectedLength = toInteger(this.documentInfo.source_size_bytes || this.documentInfo.file_size_bytes, 0);
    if (declaredLength > 4 * 1024 * 1024 || expectedLength > 4 * 1024 * 1024) throw new Error('Markdown 超過 4 MB 的線上標注上限。');
    var source = await response.arrayBuffer();
    if (source.byteLength > 4 * 1024 * 1024) throw new Error('Markdown 超過 4 MB 的線上標注上限。');
    if (expectedLength > 0 && source.byteLength !== expectedLength) throw new Error('Markdown 下載不完整或版本已變更。');
    var expectedHash = String(this.versionInfo.content_hash || this.documentInfo.content_hash || '').toLowerCase();
    if (/^[0-9a-f]{64}$/.test(expectedHash) && window.crypto && window.crypto.subtle) {
      var actualHash = bytesToHex(await window.crypto.subtle.digest('SHA-256', source));
      if (actualHash !== expectedHash) throw new Error('Markdown 內容與索引版本不一致，請重新索引。');
    }
    var text;
    try {
      text = new TextDecoder('utf-8', { fatal: true }).decode(source);
    } catch (error) {
      throw new Error('Markdown 必須使用 UTF-8 編碼才能線上標注。');
    }
    this.pdfDocument = await this.markdown.MarkdownDocument.load(text);
    this.nodes.pageTotal.textContent = '1';
    this.nodes.pageNumber.max = '1';
    if (!this.slides.length) this.slides = [{ slide_number: 1, title: 'Markdown 文件', asset_hash: null }];
    if (this.slides.length !== 1) {
      this.permissions.annotate = false;
      this.permissions.create_request = false;
      this.annotations = [];
      this.slides = [{ slide_number: 1, title: 'Markdown 文件', asset_hash: null }];
    }
  };

  PresentationReview.prototype.loadPptx = async function () {
    this.setMessage('正在下載並於瀏覽器解析 PPTX…', 'warning');
    var response = await window.fetch(this.urls.source, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/vnd.openxmlformats-officedocument.presentationml.presentation' },
      cache: 'no-store'
    });
    if (!response.ok) throw new Error('無法取得原始 PPTX（HTTP ' + response.status + '）。');
    var declaredLength = toInteger(response.headers.get('content-length'), 0);
    var expectedLength = toInteger(this.documentInfo.source_size_bytes || this.documentInfo.file_size_bytes, 0);
    if (declaredLength > 120 * 1024 * 1024) throw new Error('PPTX 超過 120 MB 的瀏覽器預覽上限。');
    if (expectedLength > 0 && declaredLength > 0 && expectedLength !== declaredLength) throw new Error('PPTX 大小與索引版本不一致，請重新索引。');
    var source = await response.arrayBuffer();
    if (source.byteLength < 64 || source.byteLength > 120 * 1024 * 1024) throw new Error('PPTX 大小無效或超過瀏覽器預覽上限。');
    if (expectedLength > 0 && source.byteLength !== expectedLength) throw new Error('PPTX 下載不完整或版本已變更。');
    var expectedHash = String(this.versionInfo.content_hash || this.documentInfo.content_hash || '').toLowerCase();
    if (/^[0-9a-f]{64}$/.test(expectedHash) && window.crypto && window.crypto.subtle) {
      var actualHash = bytesToHex(await window.crypto.subtle.digest('SHA-256', source));
      if (actualHash !== expectedHash) throw new Error('PPTX 內容與索引版本不一致，請重新索引。');
    }
    this.pdfDocument = await this.pptx.PptxDocument.load(source, this.jszip);
    if (!this.pdfDocument || !this.pdfDocument.numPages) throw new Error('PPTX 不含任何可預覽投影片。');
    this.nodes.pageTotal.textContent = String(this.pdfDocument.numPages);
    this.nodes.pageNumber.max = String(this.pdfDocument.numPages);
    if (this.slides.length && this.slides.length !== this.pdfDocument.numPages) {
      this.permissions.annotate = false;
      this.permissions.create_request = false;
      this.annotations = [];
    }
    if (!this.slides.length || this.slides.length !== this.pdfDocument.numPages) {
      this.slides = Array.from({ length: this.pdfDocument.numPages }, function (_, index) {
        return { slide_number: index + 1, title: '第 ' + (index + 1) + ' 頁', asset_hash: null };
      });
    }
  };

  PresentationReview.prototype.configurePermissions = function () {
    var hasServerSlideMap = Boolean(this.renditionInfo && this.renditionInfo.id);
    var annotate = this.permissions.annotate && hasServerSlideMap && Boolean(this.urls.save) && Boolean(this.csrfToken) && !this.versionHashMissing;
    var createRequest = this.permissions.create_request && hasServerSlideMap && Boolean(this.urls.request) && Boolean(this.csrfToken) && !this.versionHashMissing;
    this.permissions.annotate = annotate;
    this.permissions.create_request = createRequest;
    this.root.querySelectorAll('[data-annotation-tool]').forEach(function (button) { button.disabled = !annotate; });
    this.getAction('undo').disabled = !annotate;
    this.getAction('redo').disabled = !annotate;
    this.getAction('delete-selection').disabled = !annotate;
    this.nodes.save.disabled = !annotate;
    this.nodes.createRequest.hidden = !createRequest;
    if (!annotate) {
      var reason = this.versionHashMissing
        ? '此文件缺少可驗證的版本資訊，目前僅能使用瀏覽器端預覽。'
        : (hasServerSlideMap ? '目前帳號為唯讀模式；仍可瀏覽文件與匯出既有標注。' : '瀏覽器端預覽已就緒；此版本尚無伺服器頁面對照，因此暫不開放標注。');
      this.setMessage(reason, 'warning');
    }
  };

  PresentationReview.prototype.getAction = function (action) {
    return this.root.querySelector('[data-review-action="' + action + '"]');
  };

  PresentationReview.prototype.buildThumbnails = function () {
    var self = this;
    this.nodes.thumbnailList.textContent = '';
    this.thumbnailNodes.clear();
    if ('IntersectionObserver' in window) {
      this.thumbnailObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) self.renderThumbnail(toInteger(entry.target.dataset.slideNumber, 1));
        });
      }, { root: this.nodes.thumbnailList, rootMargin: '160px 0px' });
    }

    for (var page = 1; page <= this.pdfDocument.numPages; page += 1) {
      var slide = this.slides[page - 1] && typeof this.slides[page - 1] === 'object' ? this.slides[page - 1] : {};
      var frame = element('span', { className: 'presentation-thumbnail-frame', text: '載入中' });
      var thumbUrl = slide.thumbnail_url || slide.thumb_url || '';
      if (thumbUrl) {
        try {
          var image = element('img', { loading: 'lazy', alt: '', src: getSameOriginUrl(thumbUrl, '縮圖網址') });
          frame.textContent = '';
          frame.appendChild(image);
          image.addEventListener('error', function (event) {
            var failedFrame = event.currentTarget.parentElement;
            failedFrame.textContent = '縮圖無法載入';
          });
        } catch (error) {
          frame.textContent = '縮圖網址無效';
        }
      } else {
        frame.textContent = '';
        frame.appendChild(element('canvas', { 'aria-hidden': 'true' }));
      }
      var title = boundedText(slide.title || slide.label || ('第 ' + page + ' 頁'), 160);
      var count = element('span', { className: 'presentation-thumbnail-count', text: '0' });
      var button = element('button', {
        type: 'button',
        className: 'presentation-thumbnail',
        dataset: { slideNumber: String(page) },
        'aria-label': '前往第 ' + page + ' 頁：' + title
      }, [frame, element('span', { className: 'presentation-thumbnail-note' }, [element('span', { text: title }), count])]);
      button.addEventListener('click', function (event) {
        self.showPage(toInteger(event.currentTarget.dataset.slideNumber, 1), true);
      });
      this.nodes.thumbnailList.appendChild(button);
      this.thumbnailNodes.set(page, { button: button, frame: frame, count: count, slide: slide });
      if (!thumbUrl) {
        if (this.thumbnailObserver) this.thumbnailObserver.observe(button);
        else if (page <= 8) this.renderThumbnail(page);
      }
    }
    this.updateThumbnailCounts();
  };

  PresentationReview.prototype.renderThumbnail = async function (pageNumber) {
    var entry = this.thumbnailNodes.get(pageNumber);
    if (!entry || entry.button.dataset.rendered === 'true' || entry.button.dataset.rendering === 'true') return;
    var canvas = entry.frame.querySelector('canvas');
    if (!canvas) return;
    entry.button.dataset.rendering = 'true';
    try {
      var page = await this.pdfDocument.getPage(pageNumber);
      var base = page.getViewport({ scale: 1 });
      var scale = Math.min(132 / base.width, 90 / base.height);
      var viewport = page.getViewport({ scale: scale });
      canvas.width = Math.max(1, Math.floor(viewport.width));
      canvas.height = Math.max(1, Math.floor(viewport.height));
      await page.render({
        canvasContext: canvas.getContext('2d', { alpha: false }),
        viewport: viewport,
        annotationMode: 0
      }).promise;
      entry.button.dataset.rendered = 'true';
      if (this.thumbnailObserver) this.thumbnailObserver.unobserve(entry.button);
    } catch (error) {
      entry.frame.textContent = '縮圖無法載入';
    } finally {
      delete entry.button.dataset.rendering;
    }
  };

  PresentationReview.prototype.createFabricCanvas = function () {
    this.fabricCanvas = new this.fabric.Canvas(this.nodes.annotationCanvas, {
      selection: true,
      preserveObjectStacking: true,
      stopContextMenu: true,
      fireRightClick: false,
      enableRetinaScaling: true
    });
    this.fabricCanvas.selectionColor = 'rgba(20, 138, 156, .12)';
    this.fabricCanvas.selectionBorderColor = '#148a9c';
    this.fabricCanvas.selectionLineWidth = 1;
    if (this.fabricCanvas.wrapperEl) this.fabricCanvas.wrapperEl.setAttribute('aria-label', '簡報標注畫布');
  };

  PresentationReview.prototype.bindEvents = function () {
    var self = this;
    this.root.querySelectorAll('[data-review-action]').forEach(function (button) {
      button.addEventListener('click', function () { self.handleAction(button.dataset.reviewAction); });
    });
    this.nodes.pageNumber.addEventListener('change', function () { self.showPage(toInteger(self.nodes.pageNumber.value, self.pageNumber), true); });
    this.nodes.pageNumber.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        self.showPage(toInteger(self.nodes.pageNumber.value, self.pageNumber), true);
      }
    });
    this.nodes.requestInstruction.addEventListener('input', function () {
      self.nodes.requestInstruction.value = boundedText(self.nodes.requestInstruction.value, MAX_NOTE_LENGTH);
    });
    this.root.addEventListener('pointerdown', function () {
      try { self.root.focus({ preventScroll: true }); } catch (error) { self.root.focus(); }
    });
    this.root.addEventListener('keydown', function (event) { self.handleKeyboard(event); });

    this.fabricCanvas.on('mouse:down', function (event) { self.handleCanvasPointerDown(event); });
    this.fabricCanvas.on('mouse:move', function (event) { self.handleCanvasPointerMove(event); });
    this.fabricCanvas.on('mouse:up', function (event) { self.handleCanvasPointerUp(event); });
    this.fabricCanvas.on('object:modified', function () { self.commitCanvasMutation(); });
    this.fabricCanvas.on('text:editing:exited', function () { self.commitCanvasMutation(); });
    this.fabricCanvas.on('selection:created', function () { self.renderAnnotationList(); });
    this.fabricCanvas.on('selection:updated', function () { self.renderAnnotationList(); });
    this.fabricCanvas.on('selection:cleared', function () { self.renderAnnotationList(); });

    if ('ResizeObserver' in window) {
      this.resizeObserver = new ResizeObserver(function () {
        window.clearTimeout(self.resizeTimer);
        self.resizeTimer = window.setTimeout(function () { self.showPage(self.pageNumber, false); }, 180);
      });
      this.resizeObserver.observe(this.nodes.stageScroll);
    } else {
      window.addEventListener('resize', function () {
        window.clearTimeout(self.resizeTimer);
        self.resizeTimer = window.setTimeout(function () { self.showPage(self.pageNumber, false); }, 180);
      });
    }

  };

  PresentationReview.prototype.handleAction = function (action) {
    if (action.indexOf('tool-') === 0) {
      this.setTool(action.slice(5));
      return;
    }
    if (action === 'previous-page') this.showPage(this.pageNumber - 1, true);
    if (action === 'next-page') this.showPage(this.pageNumber + 1, true);
    if (action === 'zoom-in') this.changeZoom(.15);
    if (action === 'zoom-out') this.changeZoom(-.15);
    if (action === 'zoom-reset') { this.zoom = 1; this.showPage(this.pageNumber, false); }
    if (action === 'undo') this.undo();
    if (action === 'redo') this.redo();
    if (action === 'delete-selection') this.deleteSelection();
    if (action === 'save-annotations') this.saveAnnotations();
    if (action === 'copy-request') this.copyRequest();
    if (action === 'download-request') this.downloadRequest();

    if (action === 'download-annotated-slide') this.downloadAnnotatedSlide();
    if (action === 'create-request') this.createRequest();
  };

  PresentationReview.prototype.handleKeyboard = function (event) {
    var target = event.target;
    var editingField = target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName);
    if (editingField) return;
    var key = String(event.key || '').toLowerCase();
    if ((event.ctrlKey || event.metaKey) && key === 'z') {
      event.preventDefault();
      if (event.shiftKey) this.redo(); else this.undo();
    } else if ((event.ctrlKey || event.metaKey) && key === 'y') {
      event.preventDefault();
      this.redo();
    } else if (key === 'delete' || key === 'backspace') {
      event.preventDefault();
      this.deleteSelection();
    }
  };

  PresentationReview.prototype.changeZoom = function (delta) {
    this.zoom = clamp(Math.round((this.zoom + delta) * 100) / 100, .5, 2.5);
    this.showPage(this.pageNumber, false);
  };

  PresentationReview.prototype.showPage = async function (requestedPage, syncBeforeRender) {
    if (!this.pdfDocument || !this.fabricCanvas) return;
    var page = Math.min(this.pdfDocument.numPages, Math.max(1, toInteger(requestedPage, 1)));
    if (syncBeforeRender !== false && this.fabricCanvas.getWidth() > 0) this.syncCanvasToState(false);
    this.pageNumber = page;
    this.nodes.pageNumber.value = String(page);
    this.nodes.zoomLabel.textContent = Math.round(this.zoom * 100) + '%';
    this.thumbnailNodes.forEach(function (entry, number) {
      if (number === page) entry.button.setAttribute('aria-current', 'page');
      else entry.button.removeAttribute('aria-current');
    });
    var currentThumb = this.thumbnailNodes.get(page);
    if (currentThumb && syncBeforeRender) currentThumb.button.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    this.nodes.loading.classList.add('is-visible');
    var sequence = ++this.renderSequence;
    if (this.renderTask && typeof this.renderTask.cancel === 'function') {
      try { this.renderTask.cancel(); } catch (error) { /* no-op */ }
    }
    try {
      var pdfPage = await this.pdfDocument.getPage(page);
      if (sequence !== this.renderSequence) return;
      var baseViewport = pdfPage.getViewport({ scale: 1 });
      var availableWidth = Math.max(260, this.nodes.stageScroll.clientWidth - 36);
      var fitScale = clamp(availableWidth / baseViewport.width, .2, 1.8);
      var viewport = pdfPage.getViewport({ scale: fitScale * this.zoom });
      var pixelRatio = clamp(window.devicePixelRatio || 1, 1, 2);
      this.nodes.pdfCanvas.width = Math.max(1, Math.floor(viewport.width * pixelRatio));
      this.nodes.pdfCanvas.height = Math.max(1, Math.floor(viewport.height * pixelRatio));
      this.nodes.pdfCanvas.style.width = viewport.width + 'px';
      this.nodes.pdfCanvas.style.height = viewport.height + 'px';
      this.nodes.canvasStage.style.width = viewport.width + 'px';
      this.nodes.canvasStage.style.height = viewport.height + 'px';
      this.fabricCanvas.setDimensions({ width: viewport.width, height: viewport.height });
      if (this.fabricCanvas.wrapperEl) {
        this.fabricCanvas.wrapperEl.style.width = viewport.width + 'px';
        this.fabricCanvas.wrapperEl.style.height = viewport.height + 'px';
      }
      var context = this.nodes.pdfCanvas.getContext('2d', { alpha: false });
      this.renderTask = pdfPage.render({
        canvasContext: context,
        viewport: viewport,
        annotationMode: 0,
        transform: pixelRatio === 1 ? null : [pixelRatio, 0, 0, pixelRatio, 0, 0]
      });
      await this.renderTask.promise;
      if (sequence !== this.renderSequence) return;
      this.renderCurrentPageAnnotations();
      this.renderAnnotationList();
    } catch (error) {
      if (!error || error.name !== 'RenderingCancelledException') {
        this.setMessage('第 ' + page + ' 頁載入失敗，請重新整理後再試。', 'error');
      }
    } finally {
      if (sequence === this.renderSequence) this.nodes.loading.classList.remove('is-visible');
    }
  };

  PresentationReview.prototype.setTool = function (tool) {
    if (['select'].concat(ALLOWED_KINDS).indexOf(tool) === -1) return;
    if (tool !== 'select' && !this.permissions.annotate) return;
    this.currentTool = tool;
    this.root.querySelectorAll('[data-annotation-tool]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.annotationTool === tool ? 'true' : 'false');
    });
    this.nodes.canvasStage.classList.toggle('is-drawing', ['rectangle', 'arrow', 'highlight', 'number'].indexOf(tool) !== -1);
    this.nodes.canvasStage.classList.toggle('is-text-tool', tool === 'text');
    this.fabricCanvas.selection = tool === 'select';
    this.fabricCanvas.discardActiveObject();
    this.fabricCanvas.forEachObject(function (object) {
      object.selectable = tool === 'select';
      object.evented = tool === 'select';
    });
    this.fabricCanvas.requestRenderAll();
  };

  PresentationReview.prototype.canvasPointer = function (event) {
    if (typeof this.fabricCanvas.getScenePoint === 'function') return this.fabricCanvas.getScenePoint(event.e);
    return this.fabricCanvas.getPointer(event.e);
  };

  PresentationReview.prototype.handleCanvasPointerDown = function (event) {
    if (!this.permissions.annotate || this.currentTool === 'select') return;
    if (event.e && event.e.button != null && event.e.button !== 0) return;
    var point = this.canvasPointer(event);
    if (this.currentTool === 'text') {
      this.addTextAnnotation(point);
      return;
    }
    if (this.currentTool === 'number') {
      this.addNumberAnnotation(point);
      return;
    }
    this.restoreInProgress = true;
    var id = newClientId();
    var draft;
    if (this.currentTool === 'arrow') {
      draft = this.createArrowObject(point.x, point.y, point.x + 1, point.y, id, true);
    } else {
      draft = this.createBoxObject(this.currentTool, point.x, point.y, 1, 1, id, true);
    }
    draft.selectable = false;
    draft.evented = false;
    this.fabricCanvas.add(draft);
    this.drawing = { start: point, object: draft, id: id, kind: this.currentTool };
    this.fabricCanvas.requestRenderAll();
    this.restoreInProgress = false;
  };

  PresentationReview.prototype.handleCanvasPointerMove = function (event) {
    if (!this.drawing) return;
    var point = this.canvasPointer(event);
    var start = this.drawing.start;
    this.restoreInProgress = true;
    if (this.drawing.kind === 'arrow') {
      this.fabricCanvas.remove(this.drawing.object);
      this.drawing.object = this.createArrowObject(start.x, start.y, point.x, point.y, this.drawing.id, true);
      this.drawing.object.selectable = false;
      this.drawing.object.evented = false;
      this.fabricCanvas.add(this.drawing.object);
    } else {
      var strokeWidth = Number(this.drawing.object.strokeWidth || 0);
      this.drawing.object.set({
        left: Math.min(start.x, point.x),
        top: Math.min(start.y, point.y),
        width: Math.max(1, Math.abs(point.x - start.x) - strokeWidth),
        height: Math.max(1, Math.abs(point.y - start.y) - strokeWidth)
      });
      this.drawing.object.setCoords();
    }
    this.restoreInProgress = false;
    this.fabricCanvas.requestRenderAll();
  };

  PresentationReview.prototype.handleCanvasPointerUp = function (event) {
    if (!this.drawing) return;
    var point = this.canvasPointer(event);
    var distance = Math.hypot(point.x - this.drawing.start.x, point.y - this.drawing.start.y);
    var object = this.drawing.object;
    this.drawing = null;
    if (distance < 7) {
      this.restoreInProgress = true;
      this.fabricCanvas.remove(object);
      this.restoreInProgress = false;
      this.fabricCanvas.requestRenderAll();
      return;
    }
    object.__draft = false;
    object.selectable = this.currentTool === 'select';
    object.evented = this.currentTool === 'select';
    this.commitCanvasMutation();
  };

  PresentationReview.prototype.createBoxObject = function (kind, left, top, width, height, id, draft) {
    var highlight = kind === 'highlight';
    var strokeWidth = highlight ? 2 : 3;
    var object = new this.fabric.Rect({
      left: left,
      top: top,
      width: Math.max(1, width - strokeWidth),
      height: Math.max(1, height - strokeWidth),
      originX: 'left',
      originY: 'top',
      fill: highlight ? 'rgba(255, 216, 77, .30)' : 'rgba(255,255,255,0)',
      stroke: highlight ? '#d6990b' : '#d83b3b',
      strokeWidth: strokeWidth,
      strokeUniform: true,
      transparentCorners: false,
      cornerColor: '#ffffff',
      cornerStrokeColor: '#148a9c',
      borderColor: '#148a9c',
      lockScalingFlip: true,
      lockRotation: true,
      objectCaching: false
    });
    object.__annotationId = id;
    object.__annotationKind = kind;
    object.__draft = Boolean(draft);
    return object;
  };

  PresentationReview.prototype.createArrowObject = function (x1, y1, x2, y2, id, draft) {
    var length = Math.max(1, Math.hypot(x2 - x1, y2 - y1));
    var angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    var line = new this.fabric.Line([0, 0, Math.max(1, length - 9), 0], {
      stroke: '#d83b3b',
      strokeWidth: 4,
      strokeUniform: true,
      originX: 'left',
      originY: 'center'
    });
    var head = new this.fabric.Triangle({
      left: length,
      top: 0,
      width: 14,
      height: 16,
      fill: '#d83b3b',
      angle: 90,
      originX: 'center',
      originY: 'center'
    });
    var group = new this.fabric.Group([line, head], {
      left: x1,
      top: y1,
      angle: angle,
      originX: 'left',
      originY: 'center',
      transparentCorners: false,
      cornerColor: '#ffffff',
      cornerStrokeColor: '#148a9c',
      borderColor: '#148a9c',
      lockScalingFlip: true,
      objectCaching: false
    });
    group.__annotationId = id;
    group.__annotationKind = 'arrow';
    group.__draft = Boolean(draft);
    group.__arrowLength = length;
    return group;
  };

  PresentationReview.prototype.addTextAnnotation = function (point) {
    var TextClass = this.fabric.IText || this.fabric.Textbox || this.fabric.Text;
    var object = new TextClass('輸入標注', {
      left: point.x,
      top: point.y,
      fill: '#b42318',
      fontSize: Math.max(16, this.fabricCanvas.getHeight() * .036),
      fontFamily: 'Microsoft JhengHei, sans-serif',
      fontWeight: '700',
      backgroundColor: 'rgba(255,255,255,.82)',
      transparentCorners: false,
      cornerColor: '#ffffff',
      cornerStrokeColor: '#148a9c',
      borderColor: '#148a9c',
      lockScalingFlip: true,
      lockRotation: true
    });
    object.__annotationId = newClientId();
    object.__annotationKind = 'text';
    this.fabricCanvas.add(object);
    this.fabricCanvas.setActiveObject(object);
    this.commitCanvasMutation();
    if (typeof object.enterEditing === 'function') {
      object.enterEditing();
      if (typeof object.selectAll === 'function') object.selectAll();
    }
  };

  PresentationReview.prototype.addNumberAnnotation = function (point) {
    this.lastNumber += 1;
    var radius = Math.max(14, this.fabricCanvas.getHeight() * .025);
    var circle = new this.fabric.Circle({ radius: radius, fill: '#d83b3b', originX: 'center', originY: 'center' });
    var text = new this.fabric.Text(String(this.lastNumber), {
      fill: '#ffffff',
      fontSize: radius,
      fontFamily: 'Arial, sans-serif',
      fontWeight: '700',
      originX: 'center',
      originY: 'center'
    });
    var group = new this.fabric.Group([circle, text], {
      left: point.x,
      top: point.y,
      originX: 'center',
      originY: 'center',
      transparentCorners: false,
      cornerColor: '#ffffff',
      cornerStrokeColor: '#148a9c',
      borderColor: '#148a9c',
      lockScalingFlip: true,
      lockRotation: true
    });
    group.__annotationId = newClientId();
    group.__annotationKind = 'number';
    group.__annotationNumber = this.lastNumber;
    this.fabricCanvas.add(group);
    this.fabricCanvas.setActiveObject(group);
    this.commitCanvasMutation();
  };

  PresentationReview.prototype.renderCurrentPageAnnotations = function () {
    var self = this;
    this.restoreInProgress = true;
    this.fabricCanvas.discardActiveObject();
    this.fabricCanvas.clear();
    this.annotations.filter(function (annotation) { return annotation.slide_number === self.pageNumber; }).forEach(function (annotation) {
      var object = self.objectFromAnnotation(annotation);
      if (object) self.fabricCanvas.add(object);
    });
    this.restoreInProgress = false;
    this.setTool(this.currentTool);
    this.fabricCanvas.requestRenderAll();
  };

  PresentationReview.prototype.objectFromAnnotation = function (annotation) {
    var width = this.fabricCanvas.getWidth();
    var height = this.fabricCanvas.getHeight();
    var object = null;
    if (annotation.kind === 'rectangle' || annotation.kind === 'highlight') {
      object = this.createBoxObject(annotation.kind, annotation.x * width, annotation.y * height, annotation.width * width, annotation.height * height, annotation.client_id, false);
      object.set({
        stroke: annotation.style.stroke,
        strokeWidth: Math.max(1, annotation.style.stroke_width * Math.min(width, height)),
        opacity: annotation.style.opacity
      });
      if (annotation.kind === 'highlight') object.set({ fill: annotation.style.fill, opacity: Math.min(.45, annotation.style.opacity) });
    } else if (annotation.kind === 'arrow') {
      object = this.createArrowObject(annotation.x * width, annotation.y * height, annotation.x2 * width, annotation.y2 * height, annotation.client_id, false);
    } else if (annotation.kind === 'text') {
      var TextClass = this.fabric.IText || this.fabric.Textbox || this.fabric.Text;
      object = new TextClass(annotation.text || '文字標注', {
        left: annotation.x * width,
        top: annotation.y * height,
        fill: annotation.style.stroke,
        fontSize: Math.max(12, annotation.style.font_size * height),
        fontFamily: 'Microsoft JhengHei, sans-serif',
        fontWeight: '700',
        backgroundColor: 'rgba(255,255,255,.82)',
        transparentCorners: false,
        cornerColor: '#ffffff',
        cornerStrokeColor: '#148a9c',
        borderColor: '#148a9c',
        lockScalingFlip: true,
        lockRotation: true
      });
      object.__annotationId = annotation.client_id;
      object.__annotationKind = 'text';
      if (annotation.width > 0 && object.width > 0) object.scaleX = annotation.width * width / object.width;
    } else if (annotation.kind === 'number') {
      var diameter = Math.max(24, annotation.width * width);
      var circle = new this.fabric.Circle({ radius: diameter / 2, fill: annotation.style.stroke, originX: 'center', originY: 'center' });
      var numberText = new this.fabric.Text(String(annotation.number), {
        fill: '#ffffff', fontSize: diameter * .45, fontFamily: 'Arial, sans-serif', fontWeight: '700', originX: 'center', originY: 'center'
      });
      object = new this.fabric.Group([circle, numberText], {
        left: annotation.x * width + diameter / 2,
        top: annotation.y * height + diameter / 2,
        originX: 'center', originY: 'center', transparentCorners: false,
        cornerColor: '#ffffff', cornerStrokeColor: '#148a9c', borderColor: '#148a9c', lockScalingFlip: true, lockRotation: true
      });
      object.__annotationId = annotation.client_id;
      object.__annotationKind = 'number';
      object.__annotationNumber = annotation.number;
    }
    if (object) {
      object.__annotationServerId = annotation.annotation_id;
      object.__annotationVersionId = annotation.version_id;
    }
    return object;
  };

  PresentationReview.prototype.annotationFromObject = function (object, previous) {
    var width = Math.max(1, this.fabricCanvas.getWidth());
    var height = Math.max(1, this.fabricCanvas.getHeight());
    var bounds = object.getBoundingRect();
    var kind = object.__annotationKind;
    var objectText = kind === 'text' ? boundedText(object.text || '', MAX_TEXT_LENGTH) : '';
    var note = previous ? previous.note : '';
    if (kind === 'text' && (!previous || !previous.note || previous.note === previous.text)) note = objectText;
    var annotation = {
      annotation_id: object.__annotationServerId != null ? object.__annotationServerId : (previous ? previous.annotation_id : null),
      row_version: previous ? previous.row_version : '',
      client_id: object.__annotationId || (previous ? previous.client_id : newClientId()),
      version_id: this.versionInfo.id,
      slide_number: this.pageNumber,
      kind: kind,
      x: clamp(bounds.left / width, 0, 1),
      y: clamp(bounds.top / height, 0, 1),
      width: clamp(bounds.width / width, .001, 1),
      height: clamp(bounds.height / height, .001, 1),
      x2: 0,
      y2: 0,
      text: kind === 'text' ? objectText : (previous ? previous.text : ''),
      number: kind === 'number' ? Math.max(1, toInteger(object.__annotationNumber, previous ? previous.number : 1)) : (previous ? previous.number : 1),
      note: boundedText(note, MAX_NOTE_LENGTH),
      included: previous ? previous.included !== false : true,
      style: previous ? normalizeStyle(previous.style) : normalizeStyle({
        stroke: kind === 'highlight' ? '#d6990b' : '#d83b3b',
        fill: '#ffd84d',
        opacity: kind === 'highlight' ? .3 : 1,
        stroke_width: 3 / Math.min(width, height),
        font_size: kind === 'text' ? Number(object.fontSize || 16) * Number(object.scaleY || 1) / height : .036
      }),
      slide_asset_hash: this.slideHash(this.pageNumber)
    };
    if (kind === 'arrow') {
      var center = object.getCenterPoint();
      var radians = Number(object.angle || 0) * Math.PI / 180;
      var transformedLength = Math.max(1, Number(object.__arrowLength || object.width || 1) * Math.abs(Number(object.scaleX || 1)));
      annotation.x = clamp((center.x - Math.cos(radians) * transformedLength / 2) / width, 0, 1);
      annotation.y = clamp((center.y - Math.sin(radians) * transformedLength / 2) / height, 0, 1);
      annotation.x2 = clamp((center.x + Math.cos(radians) * transformedLength / 2) / width, 0, 1);
      annotation.y2 = clamp((center.y + Math.sin(radians) * transformedLength / 2) / height, 0, 1);
      annotation.width = Math.abs(annotation.x2 - annotation.x);
      annotation.height = Math.abs(annotation.y2 - annotation.y);
    } else {
      annotation.x2 = clamp(annotation.x + annotation.width, 0, 1);
      annotation.y2 = clamp(annotation.y + annotation.height, 0, 1);
    }
    return annotation;
  };

  PresentationReview.prototype.slideHash = function (pageNumber) {
    var slide = this.slides[pageNumber - 1] || {};
    return boundedText(slide.asset_hash || slide.slide_asset_hash || '', 128);
  };

  PresentationReview.prototype.syncCanvasToState = function (markDirty) {
    if (!this.fabricCanvas || this.restoreInProgress) return;
    var previousById = new Map();
    this.annotations.filter(function (annotation) { return annotation.slide_number === this.pageNumber; }, this).forEach(function (annotation) {
      previousById.set(annotation.client_id, annotation);
    });
    var current = [];
    this.fabricCanvas.getObjects().forEach(function (object) {
      if (object.__draft) return;
      current.push(this.annotationFromObject(object, previousById.get(object.__annotationId)));
    }, this);
    this.annotations = this.annotations.filter(function (annotation) { return annotation.slide_number !== this.pageNumber; }, this).concat(current);
    if (markDirty) this.updateDirtyState();
    this.updateThumbnailCounts();
  };

  PresentationReview.prototype.commitCanvasMutation = function () {
    if (this.restoreInProgress) return;
    this.syncCanvasToState(true);
    this.recordHistory(false);
    this.renderAnnotationList();
  };

  PresentationReview.prototype.captureSnapshot = function () {
    return JSON.stringify({ annotations: this.annotations, deleted_annotation_ids: this.deletedAnnotationIds });
  };

  PresentationReview.prototype.capturePersistedSnapshot = function () {
    var annotations = this.annotations.map(function (annotation) {
      var persisted = Object.assign({}, annotation);
      delete persisted.included;
      return persisted;
    });
    return JSON.stringify({ annotations: annotations, deleted_annotation_ids: this.deletedAnnotationIds });
  };

  PresentationReview.prototype.recordHistory = function (initial) {
    var snapshot = this.captureSnapshot();
    if (!initial && this.history[this.historyIndex] === snapshot) return;
    this.history = this.history.slice(0, this.historyIndex + 1);
    this.history.push(snapshot);
    if (this.history.length > HISTORY_LIMIT) this.history.shift();
    this.historyIndex = this.history.length - 1;
    this.updateUndoRedo();
  };

  PresentationReview.prototype.restoreSnapshot = function (snapshot) {
    var state = JSON.parse(snapshot);
    this.annotations = Array.isArray(state.annotations) ? state.annotations : [];
    this.deletedAnnotationIds = Array.isArray(state.deleted_annotation_ids) ? state.deleted_annotation_ids : [];
    this.renderCurrentPageAnnotations();
    this.renderAnnotationList();
    this.updateThumbnailCounts();
    this.updateDirtyState();
    this.updateUndoRedo();
  };

  PresentationReview.prototype.undo = function () {
    if (!this.permissions.annotate || this.historyIndex <= 0) return;
    this.historyIndex -= 1;
    this.restoreSnapshot(this.history[this.historyIndex]);
  };

  PresentationReview.prototype.redo = function () {
    if (!this.permissions.annotate || this.historyIndex >= this.history.length - 1) return;
    this.historyIndex += 1;
    this.restoreSnapshot(this.history[this.historyIndex]);
  };

  PresentationReview.prototype.updateUndoRedo = function () {
    var undo = this.getAction('undo');
    var redo = this.getAction('redo');
    if (undo) undo.disabled = !this.permissions.annotate || this.historyIndex <= 0;
    if (redo) redo.disabled = !this.permissions.annotate || this.historyIndex >= this.history.length - 1;
  };

  PresentationReview.prototype.deleteSelection = function () {
    if (!this.permissions.annotate || !this.fabricCanvas) return;
    var selected = this.fabricCanvas.getActiveObjects ? this.fabricCanvas.getActiveObjects() : [];
    if (!selected.length && this.fabricCanvas.getActiveObject()) selected = [this.fabricCanvas.getActiveObject()];
    if (!selected.length) return;
    this.restoreInProgress = true;
    selected.forEach(function (object) {
      var previous = this.annotations.find(function (annotation) { return annotation.client_id === object.__annotationId; });
      if (previous && previous.annotation_id != null && !this.deletedAnnotationIds.some(function (entry) { return String(entry.id) === String(previous.annotation_id); })) {
        this.deletedAnnotationIds.push({ id: previous.annotation_id, row_version: previous.row_version || '' });
      }
      this.fabricCanvas.remove(object);
    }, this);
    this.fabricCanvas.discardActiveObject();
    this.restoreInProgress = false;
    this.commitCanvasMutation();
  };

  PresentationReview.prototype.isDirty = function () {
    return this.capturePersistedSnapshot() !== this.savedSnapshot;
  };

  PresentationReview.prototype.updateDirtyState = function () {
    var dirty = this.isDirty();
    this.nodes.dirty.classList.toggle('is-dirty', dirty);
    this.nodes.dirty.textContent = dirty ? '有未儲存變更' : '標注已同步';
    this.nodes.save.disabled = !this.permissions.annotate || !this.urls.save || !dirty;
  };

  PresentationReview.prototype.updateThumbnailCounts = function () {
    var counts = {};
    this.annotations.forEach(function (annotation) { counts[annotation.slide_number] = (counts[annotation.slide_number] || 0) + 1; });
    this.thumbnailNodes.forEach(function (entry, page) {
      var count = counts[page] || 0;
      entry.count.textContent = String(count);
      entry.count.classList.toggle('has-annotations', count > 0);
    });
  };

  PresentationReview.prototype.renderAnnotationList = function () {
    var self = this;
    var active = this.fabricCanvas && this.fabricCanvas.getActiveObjects ? this.fabricCanvas.getActiveObjects().map(function (object) { return object.__annotationId; }) : [];
    this.nodes.annotationList.textContent = '';
    var pageAnnotations = this.annotations.filter(function (annotation) { return annotation.slide_number === self.pageNumber; });
    if (!pageAnnotations.length) {
      this.nodes.annotationList.appendChild(element('li', { className: 'presentation-annotation-empty', text: '本頁尚無標注。請從上方工具列選擇框選、箭頭、螢光、文字或編號。' }));
      return;
    }
    pageAnnotations.forEach(function (annotation) {
      var include = element('input', { type: 'checkbox', checked: annotation.included !== false, 'aria-label': '將此標注納入修改需求' });
      var note = element('textarea', {
        maxlength: String(MAX_NOTE_LENGTH),
        placeholder: '補充這個區域要修改的內容',
        'aria-label': KIND_LABELS[annotation.kind] + '的修改說明'
      });
      note.value = annotation.note || '';
      var go = element('button', { type: 'button', text: '選取' });
      var item = element('li', {
        className: 'presentation-annotation-item' + (active.indexOf(annotation.client_id) !== -1 ? ' is-selected' : ''),
        dataset: { annotationId: annotation.client_id }
      }, [include, element('div', { className: 'presentation-annotation-item-main' }, [
        element('div', { className: 'presentation-annotation-item-heading' }, [
          element('span', { text: KIND_LABELS[annotation.kind] + (annotation.kind === 'number' ? ' ' + annotation.number : '') }),
          go
        ]),
        note
      ])]);
      include.addEventListener('change', function () {
        annotation.included = include.checked;
      });
      note.addEventListener('input', function () {
        annotation.note = boundedText(note.value, MAX_NOTE_LENGTH);
        if (note.value !== annotation.note) note.value = annotation.note;
        self.scheduleMetadataHistory();
      });
      go.addEventListener('click', function () {
        var object = self.fabricCanvas.getObjects().find(function (candidate) { return candidate.__annotationId === annotation.client_id; });
        if (object) {
          self.setTool('select');
          self.fabricCanvas.setActiveObject(object);
          self.fabricCanvas.requestRenderAll();
          self.renderAnnotationList();
        }
      });
      self.nodes.annotationList.appendChild(item);
    });
  };

  PresentationReview.prototype.scheduleMetadataHistory = function () {
    var self = this;
    this.updateDirtyState();
    window.clearTimeout(this.historyTimer);
    this.historyTimer = window.setTimeout(function () { self.recordHistory(false); }, 350);
  };

  PresentationReview.prototype.serializableAnnotations = function () {
    return this.annotations.map(function (annotation) {
      var x = annotation.kind === 'arrow' ? Math.min(annotation.x, annotation.x2) : annotation.x;
      var y = annotation.kind === 'arrow' ? Math.min(annotation.y, annotation.y2) : annotation.y;
      var width = annotation.kind === 'arrow' ? Math.abs(annotation.x2 - annotation.x) : annotation.width;
      var height = annotation.kind === 'arrow' ? Math.abs(annotation.y2 - annotation.y) : annotation.height;
      x = clamp(x, 0, 1);
      y = clamp(y, 0, 1);
      width = clamp(width, annotation.kind === 'arrow' ? 0 : .001, Math.max(0, 1 - x));
      height = clamp(height, annotation.kind === 'arrow' ? 0 : .001, Math.max(0, 1 - y));
      var geometry = { x: x, y: y, width: width, height: height };
      if (annotation.kind === 'arrow') {
        geometry.points = [
          { x: annotation.x, y: annotation.y },
          { x: annotation.x2, y: annotation.y2 }
        ];
      }
      if (annotation.kind === 'text') geometry.text = boundedText(annotation.text, MAX_TEXT_LENGTH);
      if (annotation.kind === 'number') geometry.number = Math.max(1, toInteger(annotation.number, 1));
      var type = annotation.kind === 'number' ? 'marker' : annotation.kind;
      var comment = annotation.note || annotation.text || (annotation.kind === 'number' ? '編號 ' + annotation.number : '');
      return {
        id: annotation.annotation_id || '',
        annotation_id: annotation.annotation_id,
        row_version: annotation.row_version,
        client_id: annotation.client_id,
        version_id: annotation.version_id,
        slide_number: annotation.slide_number,
        slide_asset_hash: annotation.slide_asset_hash,
        kind: annotation.kind,
        type: type,
        annotation_type: type,
        x: x,
        y: y,
        width: width,
        height: height,
        geometry: geometry,
        comment: boundedText(comment, 2000),
        text: annotation.text,
        number: annotation.number,
        note: annotation.note,
        included: annotation.included,
        style: {
          stroke: annotation.style.stroke,
          fill: annotation.style.fill,
          textColor: annotation.style.stroke,
          opacity: annotation.style.opacity,
          strokeWidth: Math.round(clamp(annotation.style.stroke_width * 1000, 1, 12)),
          fontSize: Math.round(clamp(annotation.style.font_size * 900, 10, 48))
        }
      };
    });
  };

  PresentationReview.prototype.annotationMatchKey = function (annotation) {
    function coordinate(value) { return Number(value || 0).toFixed(5); }
    return [
      annotation.slide_number,
      annotation.kind,
      coordinate(annotation.x),
      coordinate(annotation.y),
      coordinate(annotation.width),
      coordinate(annotation.height),
      coordinate(annotation.x2),
      coordinate(annotation.y2)
    ].join('|');
  };

  PresentationReview.prototype.saveAnnotations = async function () {
    if (!this.permissions.annotate || !this.urls.save || !this.isDirty()) return;
    this.syncCanvasToState(false);
    this.nodes.save.disabled = true;
    this.root.setAttribute('aria-busy', 'true');
    this.setMessage('正在儲存標注…', 'warning');
    try {
      var requestSelection = new Map();
      this.annotations.forEach(function (annotation) {
        requestSelection.set(this.annotationMatchKey(annotation), annotation.included !== false);
      }, this);
      var payload = await fetchJson(this.urls.save, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.csrfToken
        },
        body: JSON.stringify({
          document_id: this.documentInfo.id,
          version_id: this.versionInfo.id,
          rendition_id: this.renditionInfo.id || '',
          rendition_hash: this.renditionInfo.asset_hash || '',
          content_hash: this.versionInfo.content_hash,
          annotations: this.serializableAnnotations(),
          deleted_annotation_ids: this.deletedAnnotationIds.slice()
        })
      });
      if (payload.version && String(payload.version.id || payload.version.version_id || '') !== String(this.versionInfo.id)) {
        throw new ApiError('儲存後版本識別不一致，請重新載入確認。', 409, payload);
      }
      if (Array.isArray(payload.annotations)) {
        this.annotations = payload.annotations.map(function (entry) {
          var normalized = normalizeAnnotation(entry, this.versionInfo.id);
          if (normalized) {
            var matchKey = this.annotationMatchKey(normalized);
            if (requestSelection.has(matchKey)) normalized.included = requestSelection.get(matchKey);
          }
          return normalized;
        }, this).filter(Boolean);
      }
      this.deletedAnnotationIds = [];
      this.history = [];
      this.historyIndex = -1;
      this.recordHistory(true);
      this.savedSnapshot = this.capturePersistedSnapshot();
      this.renderCurrentPageAnnotations();
      this.renderAnnotationList();
      this.updateDirtyState();
      this.setMessage(apiMessage(payload, '標注已儲存。'), 'success');
    } catch (error) {
      if (error && error.status === 409) {
        this.setMessage('原始文件或預覽版本已更新。為避免標注套用到錯誤版本，請重新載入頁面後再標注。', 'error');
      } else {
        this.setMessage(error && error.message ? error.message : '標注儲存失敗。', 'error');
      }
    } finally {
      this.root.removeAttribute('aria-busy');
      this.updateDirtyState();
    }
  };

  PresentationReview.prototype.buildRequestPayload = function () {
    this.syncCanvasToState(false);
    var selected = this.annotations.filter(function (annotation) { return annotation.included !== false; });
    var title = boundedText(this.documentInfo.title || this.documentInfo.file_name || '未命名文件', 255);
    return {
      document_id: this.documentInfo.id,
      document_title: title,
      file_name: boundedText(this.documentInfo.file_name || '', 255),
      document_format: this.documentFormat,
      version_id: this.versionInfo.id,
      rendition_id: this.renditionInfo.id || '',
      rendition_hash: this.renditionInfo.asset_hash || '',
      content_hash: this.versionInfo.content_hash,
      title: boundedText('修改：' + title, 255),
      instruction: boundedText(this.nodes.requestInstruction.value, MAX_NOTE_LENGTH),
      annotation_ids: selected.map(function (annotation) { return annotation.annotation_id; }).filter(Boolean),
      annotation_refs: selected.filter(function (annotation) { return Boolean(annotation.annotation_id); }).map(function (annotation) {
        return { id: annotation.annotation_id, row_version: annotation.row_version || '' };
      }),
      submit: false,
      annotations: selected.map(function (annotation) {
        return {
          annotation_id: annotation.annotation_id,
          client_id: annotation.client_id,
          slide_number: annotation.slide_number,
          slide_asset_hash: annotation.slide_asset_hash,
          kind: annotation.kind,
          kind_label: KIND_LABELS[annotation.kind],
          geometry: annotation.kind === 'arrow'
            ? { x: annotation.x, y: annotation.y, x2: annotation.x2, y2: annotation.y2 }
            : { x: annotation.x, y: annotation.y, width: annotation.width, height: annotation.height },
          text: annotation.text,
          number: annotation.number,
          note: annotation.note
        };
      }),
      generated_at: new Date().toISOString()
    };
  };

  PresentationReview.prototype.requestMarkdown = function (payload) {
    var isMarkdown = payload.document_format === 'md';
    var lines = [
      '# ' + (isMarkdown ? 'Markdown 文件修改需求' : '簡報修改需求'),
      '',
      '- 文件：' + (payload.document_title || payload.file_name),
      '- 檔名：' + (payload.file_name || '未提供'),
      '- 文件 ID：' + payload.document_id,
      '- 版本：' + payload.version_id,
      '- 預覽版本：' + payload.rendition_id,
      '- 預覽雜湊：' + payload.rendition_hash,
      '- 內容雜湊：' + payload.content_hash,
      '',
      '## 整體修改說明',
      '',
      payload.instruction || (isMarkdown
        ? '請依下列標注調整 Markdown，並維持未標注區域的原始內容與結構。'
        : '請依下列標注調整簡報，並維持未標注區域的原始內容與版型。'),
      '',
      '## 頁面標注',
      ''
    ];
    if (!payload.annotations.length) {
      lines.push('- 尚未勾選任何標注。');
    } else {
      payload.annotations.forEach(function (annotation, index) {
        lines.push((index + 1) + '. 第 ' + annotation.slide_number + ' 頁｜' + annotation.kind_label);
        if (annotation.kind === 'number') lines.push('   - 編號：' + annotation.number);
        if (annotation.text) lines.push('   - 標注文字：' + annotation.text.replace(/\r?\n/g, ' '));
        lines.push('   - 修改說明：' + (annotation.note || '請檢查並調整此標注區域。').replace(/\r?\n/g, ' '));
        lines.push('   - 正規化座標：`' + JSON.stringify(annotation.geometry) + '`');
        if (annotation.slide_asset_hash) lines.push('   - 頁面雜湊：`' + annotation.slide_asset_hash + '`');
      });
    }
    lines.push('', isMarkdown
      ? '> 請以新版本 .md 檔案交付，不要直接覆寫目前來源；完成後提供修改摘要與標注對照。'
      : '> 請以新版本檔案交付，不要直接覆寫目前的來源 PPTX；完成後提供修改摘要與頁次對照。');
    return lines.join('\n');
  };

  PresentationReview.prototype.copyRequest = async function () {
    var markdown = this.requestMarkdown(this.buildRequestPayload());
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(markdown);
      } else {
        var temporary = element('textarea', { 'aria-hidden': 'true' });
        temporary.value = markdown;
        temporary.style.position = 'fixed';
        temporary.style.left = '-9999px';
        document.body.appendChild(temporary);
        temporary.select();
        document.execCommand('copy');
        document.body.removeChild(temporary);
      }
      this.setMessage('Discord 修改指令已複製。', 'success');
    } catch (error) {
      this.setMessage('瀏覽器無法存取剪貼簿，請改用「下載需求 .md」。', 'error');
    }
  };

  PresentationReview.prototype.downloadRequest = function () {
    if (this.lastCreatedRequestExportUrl) {
      var serverAnchor = element('a', { href: this.lastCreatedRequestExportUrl, download: '' });
      document.body.appendChild(serverAnchor);
      serverAnchor.click();
      document.body.removeChild(serverAnchor);
      this.setMessage('已下載伺服器保存的不可變更修改需求。', 'success');
      return;
    }
    var payload = this.buildRequestPayload();
    var blob = new Blob([this.requestMarkdown(payload)], { type: 'text/markdown;charset=utf-8' });
    var objectUrl = URL.createObjectURL(blob);
    var safeName = (payload.document_title || '文件').replace(/[\\/:*?"<>|\u0000-\u001F]/g, '_').slice(0, 80);
    var anchor = element('a', { href: objectUrl, download: safeName + '_修改需求.md' });
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
    this.setMessage('修改需求檔案已下載。', 'success');
  };

  PresentationReview.prototype.downloadAnnotatedSlide = function () {
    if (!this.pdfDocument || !this.fabricCanvas || !this.nodes.pdfCanvas.width || !this.nodes.pdfCanvas.height) {
      this.setMessage('投影片尚未完成顯示，暫時無法匯出標注圖。', 'warning');
      return;
    }
    this.syncCanvasToState(false);
    this.renderCurrentPageAnnotations();
    var output = document.createElement('canvas');
    output.width = this.nodes.pdfCanvas.width;
    output.height = this.nodes.pdfCanvas.height;
    var context = output.getContext('2d', { alpha: false });
    if (!context) {
      this.setMessage('瀏覽器無法建立標注圖。', 'error');
      return;
    }
    context.drawImage(this.nodes.pdfCanvas, 0, 0, output.width, output.height);
    var annotationLayer = this.fabricCanvas.lowerCanvasEl;
    if (annotationLayer) {
      context.drawImage(annotationLayer, 0, 0, output.width, output.height);
    }
    var self = this;
    output.toBlob(function (blob) {
      if (!blob) {
        self.setMessage('瀏覽器無法輸出標注圖。', 'error');
        return;
      }
      var objectUrl = URL.createObjectURL(blob);
      var safeName = (self.documentInfo.title || self.documentInfo.file_name || '文件')
        .replace(/[\\/:*?"<>|\u0000-\u001F]/g, '_').slice(0, 70);
      var pageLabel = String(self.pageNumber).padStart(3, '0');
      var anchor = element('a', {
        href: objectUrl,
        download: safeName + '_第' + pageLabel + '頁_標注.png'
      });
      document.body.appendChild(anchor);
      anchor.click();
      document.body.removeChild(anchor);
      window.setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
      self.setMessage('第 ' + self.pageNumber + ' 頁標注圖已下載，可連同需求 .md 附到 Discord。', 'success');
    }, 'image/png');
  };

  PresentationReview.prototype.createRequest = async function () {
    if (!this.permissions.create_request || !this.urls.request) return;
    if (this.isDirty()) {
      this.setMessage('請先儲存標注，再建立修改需求，以確保每筆標注都有可驗證的版本。', 'warning');
      return;
    }
    var payload = this.buildRequestPayload();
    if (!payload.annotation_ids.length) {
      this.setMessage('請至少勾選一筆已儲存的標注。', 'warning');
      return;
    }
    this.nodes.createRequest.disabled = true;
    this.root.setAttribute('aria-busy', 'true');
    this.setMessage('正在建立修改需求…', 'warning');
    try {
      var response = await fetchJson(this.urls.request, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.csrfToken
        },
        body: JSON.stringify(payload)
      });
      var exportUrl = response && response.request ? response.request.export_url : '';
      if (exportUrl) {
        this.lastCreatedRequestExportUrl = getSameOriginUrl(exportUrl, '修改需求匯出網址');
        this.nodes.downloadRequest.textContent = '下載已建立需求 .md';
      }
      this.setMessage(apiMessage(response, '修改需求已建立；可下載需求，完成修改後請交由管理者依Phase 1流程放入正式文件庫並重新索引。'), 'success');
    } catch (error) {
      if (error && error.status === 409) {
        this.setMessage('文件版本已更新，請重新載入後再建立修改需求。', 'error');
      } else {
        this.setMessage(error && error.message ? error.message : '修改需求建立失敗。', 'error');
      }
    } finally {
      this.root.removeAttribute('aria-busy');
      this.nodes.createRequest.disabled = false;
    }
  };

  function start() {
    document.querySelectorAll(ROOT_SELECTOR).forEach(function (root) {
      if (root.dataset.presentationInitialized === 'true') return;
      root.dataset.presentationInitialized = 'true';
      var review = new PresentationReview(root);
      review.init();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
}());
