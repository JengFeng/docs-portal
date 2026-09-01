(function (global) {
  'use strict';

  var BASE_WIDTH = 1100;
  var MIN_HEIGHT = 760;
  var MAX_HEIGHT = 30000;
  var MARGIN_X = 72;
  var MARGIN_TOP = 64;
  var MARGIN_BOTTOM = 72;

  function RendererError(message) {
    this.name = 'MarkdownRendererError';
    this.message = message;
    if (Error.captureStackTrace) Error.captureStackTrace(this, RendererError);
  }
  RendererError.prototype = Object.create(Error.prototype);

  function cleanInline(value) {
    return String(value || '')
      .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 ($2)')
      .replace(/(`{1,2}|\*\*|__|~~)/g, '')
      .replace(/<[^>]*>/g, '')
      .replace(/\t/g, '    ');
  }

  function characterWeight(character) {
    return /[\u2e80-\u9fff\uf900-\ufaff\uff01-\uff60]/.test(character) ? 1 : 0.56;
  }

  function wrapText(text, capacity) {
    var source = String(text || '');
    if (!source) return [''];
    var output = [];
    var current = '';
    var weight = 0;
    Array.from(source).forEach(function (character) {
      var nextWeight = characterWeight(character);
      if (weight + nextWeight > capacity && current !== '') {
        output.push(current);
        current = '';
        weight = 0;
      }
      current += character;
      weight += nextWeight;
    });
    if (current || !output.length) output.push(current);
    return output;
  }

  function styleFor(kind, level) {
    if (kind === 'heading') {
      var sizes = [38, 32, 28, 24, 21, 19];
      return { fontSize: sizes[Math.max(0, Math.min(5, level - 1))], lineHeight: 1.32, weight: 700, color: '#123e54', before: level <= 2 ? 22 : 14, after: 10 };
    }
    if (kind === 'code') return { fontSize: 17, lineHeight: 1.55, weight: 400, color: '#d9edf3', before: 0, after: 0, background: '#12303f', mono: true };
    if (kind === 'quote') return { fontSize: 19, lineHeight: 1.55, weight: 400, color: '#355f70', before: 4, after: 4, quote: true };
    if (kind === 'list') return { fontSize: 19, lineHeight: 1.55, weight: 400, color: '#173748', before: 2, after: 2 };
    return { fontSize: 19, lineHeight: 1.58, weight: 400, color: '#173748', before: 2, after: 5 };
  }

  function buildLayout(markdown) {
    var lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
    var layout = [];
    var y = MARGIN_TOP;
    var inCode = false;
    var truncated = false;

    function addEntry(kind, text, level) {
      var style = styleFor(kind, level || 0);
      var capacity = Math.max(18, Math.floor((BASE_WIDTH - MARGIN_X * 2 - (kind === 'list' ? 28 : 0)) / style.fontSize));
      var wrapped = wrapText(text, capacity);
      var lineHeight = Math.ceil(style.fontSize * style.lineHeight);
      var height = wrapped.length * lineHeight + style.before + style.after;
      if (y + height + MARGIN_BOTTOM > MAX_HEIGHT) {
        truncated = true;
        return false;
      }
      layout.push({ kind: kind, text: text, lines: wrapped, y: y + style.before, height: height, style: style, level: level || 0 });
      y += height;
      return true;
    }

    for (var index = 0; index < lines.length; index += 1) {
      var raw = lines[index];
      if (/^\s*```/.test(raw)) {
        inCode = !inCode;
        if (!inCode) y += 10;
        else y += 8;
        continue;
      }
      if (inCode) {
        if (!addEntry('code', raw || ' ', 0)) break;
        continue;
      }
      if (/^\s*$/.test(raw)) {
        y += 16;
        continue;
      }
      var heading = raw.match(/^\s*(#{1,6})\s+(.+)$/);
      if (heading) {
        if (!addEntry('heading', cleanInline(heading[2]), heading[1].length)) break;
        continue;
      }
      if (/^\s*(?:---+|___+|\*\*\*+)\s*$/.test(raw)) {
        if (y + 30 > MAX_HEIGHT - MARGIN_BOTTOM) { truncated = true; break; }
        layout.push({ kind: 'rule', y: y + 12, height: 30 });
        y += 30;
        continue;
      }
      var quote = raw.match(/^\s*>\s?(.*)$/);
      if (quote) {
        if (!addEntry('quote', cleanInline(quote[1]), 0)) break;
        continue;
      }
      var list = raw.match(/^\s*(?:[-+*]|\d+[.)])\s+(.+)$/);
      if (list) {
        if (!addEntry('list', '• ' + cleanInline(list[1]), 0)) break;
        continue;
      }
      if (!addEntry('paragraph', cleanInline(raw), 0)) break;
    }
    if (truncated) addEntry('paragraph', '內容超過線上標注畫布上限，請下載原始 Markdown 查看其餘內容。', 0);
    return { entries: layout, height: Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, y + MARGIN_BOTTOM)), truncated: truncated };
  }

  function MarkdownPage(documentModel) {
    this.documentModel = documentModel;
  }
  MarkdownPage.prototype.getViewport = function (options) {
    var scale = Number(options && options.scale) || 1;
    return { width: BASE_WIDTH * scale, height: this.documentModel.height * scale, scale: scale };
  };
  MarkdownPage.prototype.render = function (options) {
    var documentModel = this.documentModel;
    var cancelled = false;
    var task = {
      cancel: function () { cancelled = true; },
      promise: Promise.resolve().then(function () {
        if (cancelled) throw new RendererError('MarkdownRenderingCancelled');
        var context = options && options.canvasContext;
        var canvas = context && context.canvas;
        if (!canvas) throw new RendererError('瀏覽器無法建立 Markdown 畫布。');
        var scaleX = canvas.width / BASE_WIDTH;
        var scaleY = canvas.height / documentModel.height;
        context.save();
        context.setTransform(scaleX, 0, 0, scaleY, 0, 0);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, BASE_WIDTH, documentModel.height);
        context.fillStyle = '#148a9c';
        context.fillRect(0, 0, BASE_WIDTH, 12);
        documentModel.layout.forEach(function (entry) {
          if (entry.kind === 'rule') {
            context.strokeStyle = '#cbdde3';
            context.lineWidth = 2;
            context.beginPath();
            context.moveTo(MARGIN_X, entry.y);
            context.lineTo(BASE_WIDTH - MARGIN_X, entry.y);
            context.stroke();
            return;
          }
          var style = entry.style;
          var lineHeight = Math.ceil(style.fontSize * style.lineHeight);
          var textX = MARGIN_X + (entry.kind === 'list' ? 22 : 0);
          if (style.background) {
            context.fillStyle = style.background;
            context.fillRect(MARGIN_X - 18, entry.y - 8, BASE_WIDTH - (MARGIN_X - 18) * 2, entry.lines.length * lineHeight + 16);
          }
          if (style.quote) {
            context.fillStyle = '#41a8b7';
            context.fillRect(MARGIN_X - 18, entry.y - 4, 5, entry.lines.length * lineHeight + 8);
          }
          context.font = style.weight + ' ' + style.fontSize + 'px ' + (style.mono ? 'Consolas, monospace' : '"Noto Sans TC", "Microsoft JhengHei", sans-serif');
          context.fillStyle = style.color;
          context.textBaseline = 'top';
          entry.lines.forEach(function (line, lineIndex) {
            context.fillText(line, textX, entry.y + lineIndex * lineHeight, BASE_WIDTH - textX - MARGIN_X);
          });
        });
        context.restore();
      })
    };
    return task;
  };

  function MarkdownDocument(source) {
    var built = buildLayout(source);
    this.source = String(source || '');
    this.layout = built.entries;
    this.height = built.height;
    this.truncated = built.truncated;
    this.numPages = 1;
  }
  MarkdownDocument.prototype.getPage = function (pageNumber) {
    if (Number(pageNumber) !== 1) return Promise.reject(new RendererError('Markdown 頁次無效。'));
    return Promise.resolve(new MarkdownPage(this));
  };
  MarkdownDocument.load = function (source) {
    if (typeof source !== 'string') return Promise.reject(new RendererError('Markdown 內容格式無效。'));
    return Promise.resolve(new MarkdownDocument(source));
  };

  global.TWWaterMarkdown = {
    MarkdownDocument: MarkdownDocument,
    RendererError: RendererError
  };
})(typeof window !== 'undefined' ? window : globalThis);
