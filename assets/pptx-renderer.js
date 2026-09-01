(function (global) {
  'use strict';

  var EMU_PER_PIXEL = 9525;
  var EMU_PER_POINT = 12700;
  var MAX_ARCHIVE_BYTES = 120 * 1024 * 1024;
  var MAX_ENTRIES = 6000;
  var MAX_XML_BYTES = 6 * 1024 * 1024;
  var MAX_MEDIA_BYTES = 32 * 1024 * 1024;

  function RendererError(message) {
    this.name = 'PptxRendererError';
    this.message = message;
  }
  RendererError.prototype = Object.create(Error.prototype);

  function fail(message) {
    throw new RendererError(message);
  }

  function escapeXml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&apos;');
  }

  function numberAttr(node, name, fallback) {
    var value = node && node.getAttribute ? Number(node.getAttribute(name)) : NaN;
    return Number.isFinite(value) ? value : fallback;
  }

  function localName(node) {
    return String(node && (node.localName || node.nodeName) || '').replace(/^.*:/, '');
  }

  function elementChildren(node) {
    return Array.prototype.filter.call(node && node.childNodes || [], function (child) {
      return child.nodeType === 1;
    });
  }

  function childrenNamed(node, name) {
    return elementChildren(node).filter(function (child) { return localName(child) === name; });
  }

  function firstChild(node, name) {
    return childrenNamed(node, name)[0] || null;
  }

  function descendants(node, name) {
    if (!node || !node.getElementsByTagName) return [];
    return Array.prototype.filter.call(node.getElementsByTagName('*'), function (child) {
      return localName(child) === name;
    });
  }

  function firstDescendant(node, name) {
    return descendants(node, name)[0] || null;
  }

  function attributeByLocalName(node, name) {
    if (!node || !node.attributes) return '';
    for (var index = 0; index < node.attributes.length; index += 1) {
      var attribute = node.attributes[index];
      if (String(attribute.localName || attribute.name).replace(/^.*:/, '') === name) return attribute.value;
    }
    return '';
  }

  function relationshipId(node) {
    if (!node || !node.attributes) return '';
    for (var index = 0; index < node.attributes.length; index += 1) {
      var attribute = node.attributes[index];
      if (String(attribute.value || '').indexOf('rId') === 0) return attribute.value;
    }
    return '';
  }

  function parseXml(text, label) {
    var documentNode = new DOMParser().parseFromString(text, 'application/xml');
    if (descendants(documentNode, 'parsererror').length) fail('PPTX 內的 ' + label + ' XML 無法解析。');
    return documentNode;
  }

  function normalizePartPath(path) {
    var output = [];
    String(path || '').replace(/\\/g, '/').split('/').forEach(function (segment) {
      if (!segment || segment === '.') return;
      if (segment === '..') {
        if (!output.length) fail('PPTX 關聯路徑超出封裝範圍。');
        output.pop();
        return;
      }
      output.push(segment);
    });
    return output.join('/');
  }

  function resolveTarget(partPath, target) {
    if (/^[a-z]+:/i.test(target || '')) return '';
    if (String(target).charAt(0) === '/') return normalizePartPath(String(target).slice(1));
    var slash = partPath.lastIndexOf('/');
    return normalizePartPath((slash < 0 ? '' : partPath.slice(0, slash + 1)) + target);
  }

  function relationshipPath(partPath) {
    var slash = partPath.lastIndexOf('/');
    var directory = slash < 0 ? '' : partPath.slice(0, slash + 1);
    var file = slash < 0 ? partPath : partPath.slice(slash + 1);
    return directory + '_rels/' + file + '.rels';
  }

  async function readZipText(zip, path, required) {
    var entry = zip.file(path);
    if (!entry) {
      if (required) fail('PPTX 缺少必要檔案：' + path + '。');
      return '';
    }
    var size = entry._data && Number(entry._data.uncompressedSize);
    if (Number.isFinite(size) && size > MAX_XML_BYTES) fail('PPTX XML 超過安全大小限制。');
    var text = await entry.async('string');
    if (text.length > MAX_XML_BYTES) fail('PPTX XML 超過安全大小限制。');
    return text;
  }

  async function readXml(zip, path, required) {
    var text = await readZipText(zip, path, required);
    return text ? parseXml(text, path) : null;
  }

  async function readRelationships(zip, partPath) {
    var documentNode = await readXml(zip, relationshipPath(partPath), false);
    var map = {};
    if (!documentNode) return map;
    descendants(documentNode, 'Relationship').forEach(function (relationship) {
      var id = relationship.getAttribute('Id') || '';
      var mode = relationship.getAttribute('TargetMode') || '';
      if (!id || mode.toLowerCase() === 'external') return;
      map[id] = {
        path: resolveTarget(partPath, relationship.getAttribute('Target') || ''),
        type: relationship.getAttribute('Type') || ''
      };
    });
    return map;
  }

  function colorFromNode(node, theme, fallback) {
    if (!node) return fallback;
    var srgb = firstDescendant(node, 'srgbClr');
    if (srgb && /^[0-9a-f]{6}$/i.test(srgb.getAttribute('val') || '')) return '#' + srgb.getAttribute('val');
    var system = firstDescendant(node, 'sysClr');
    if (system) {
      var last = system.getAttribute('lastClr') || '';
      if (/^[0-9a-f]{6}$/i.test(last)) return '#' + last;
    }
    var scheme = firstDescendant(node, 'schemeClr');
    if (scheme) return theme[scheme.getAttribute('val')] || fallback;
    var preset = firstDescendant(node, 'prstClr');
    if (preset) {
      var known = { white: '#ffffff', black: '#000000', red: '#ff0000', blue: '#0000ff', green: '#008000', yellow: '#ffff00', gray: '#808080', ltGray: '#d3d3d3', dkGray: '#404040' };
      return known[preset.getAttribute('val')] || fallback;
    }
    return fallback;
  }

  function alphaFromNode(node, fallback) {
    var alpha = firstDescendant(node, 'alpha');
    return alpha ? Math.max(0, Math.min(1, numberAttr(alpha, 'val', fallback * 100000) / 100000)) : fallback;
  }

  function parseTheme(documentNode) {
    var theme = {
      dk1: '#000000', lt1: '#ffffff', dk2: '#1f497d', lt2: '#eeeeee',
      accent1: '#4f81bd', accent2: '#c0504d', accent3: '#9bbb59',
      accent4: '#8064a2', accent5: '#4bacc6', accent6: '#f79646',
      hlink: '#0000ff', folHlink: '#800080', tx1: '#000000', bg1: '#ffffff',
      tx2: '#1f497d', bg2: '#eeeeee'
    };
    if (!documentNode) return theme;
    var scheme = firstDescendant(documentNode, 'clrScheme');
    elementChildren(scheme).forEach(function (entry) {
      var key = localName(entry);
      theme[key] = colorFromNode(entry, theme, theme[key] || '#808080');
    });
    theme.tx1 = theme.dk1;
    theme.bg1 = theme.lt1;
    theme.tx2 = theme.dk2;
    theme.bg2 = theme.lt2;
    return theme;
  }

  function transformOf(node) {
    var properties = firstChild(node, 'spPr') || firstChild(node, 'picPr') || node;
    var xfrm = firstDescendant(properties, 'xfrm');
    if (!xfrm) return null;
    var offset = firstChild(xfrm, 'off') || firstDescendant(xfrm, 'off');
    var extent = firstChild(xfrm, 'ext') || firstDescendant(xfrm, 'ext');
    if (!offset || !extent) return null;
    return {
      x: numberAttr(offset, 'x', 0), y: numberAttr(offset, 'y', 0),
      width: Math.max(1, numberAttr(extent, 'cx', 1)), height: Math.max(1, numberAttr(extent, 'cy', 1)),
      rotation: numberAttr(xfrm, 'rot', 0) / 60000,
      flipH: xfrm.getAttribute('flipH') === '1', flipV: xfrm.getAttribute('flipV') === '1'
    };
  }

  function shapeFill(properties, theme) {
    if (!properties || firstChild(properties, 'noFill')) return { color: 'none', opacity: 1 };
    var solid = firstChild(properties, 'solidFill');
    if (solid) return { color: colorFromNode(solid, theme, '#ffffff'), opacity: alphaFromNode(solid, 1) };
    var gradient = firstChild(properties, 'gradFill');
    if (gradient) {
      var stops = descendants(gradient, 'gs');
      return { color: stops.length ? colorFromNode(stops[0], theme, '#ffffff') : '#ffffff', opacity: 1 };
    }
    return { color: 'none', opacity: 1 };
  }

  function lineStyle(properties, theme) {
    var line = firstDescendant(properties, 'ln');
    if (!line || firstDescendant(line, 'noFill')) return { color: 'none', width: 0, dash: '', head: '', tail: '' };
    var dashNode = firstDescendant(line, 'prstDash');
    var dash = dashNode && dashNode.getAttribute('val') !== 'solid' ? '70000 45000' : '';
    var head = firstDescendant(line, 'headEnd');
    var tail = firstDescendant(line, 'tailEnd');
    return {
      color: colorFromNode(firstDescendant(line, 'solidFill'), theme, '#555555'),
      width: Math.max(9525, numberAttr(line, 'w', 12700)),
      dash: dash,
      head: head ? head.getAttribute('type') || '' : '',
      tail: tail ? tail.getAttribute('type') || '' : ''
    };
  }

  function transformAttribute(box) {
    var values = [];
    if (box.rotation) values.push('rotate(' + box.rotation + ' ' + (box.x + box.width / 2) + ' ' + (box.y + box.height / 2) + ')');
    if (box.flipH || box.flipV) {
      values.push('translate(' + (box.flipH ? 2 * box.x + box.width : 0) + ' ' + (box.flipV ? 2 * box.y + box.height : 0) + ')');
      values.push('scale(' + (box.flipH ? -1 : 1) + ' ' + (box.flipV ? -1 : 1) + ')');
    }
    return values.length ? ' transform="' + values.join(' ') + '"' : '';
  }

  function wrapText(text, width, fontSize) {
    var source = String(text || '').replace(/\r/g, '');
    if (!source) return [''];
    var maximum = Math.max(1, Math.floor(width / Math.max(fontSize * 0.56, 1)));
    var lines = [];
    source.split('\n').forEach(function (logicalLine) {
      if (!logicalLine) {
        lines.push('');
        return;
      }
      var current = '';
      Array.from(logicalLine).forEach(function (character) {
        var weight = /[\u2e80-\uffff]/.test(character) ? 1 : 0.58;
        var currentWeight = Array.from(current).reduce(function (sum, item) { return sum + (/[\u2e80-\uffff]/.test(item) ? 1 : 0.58); }, 0);
        if (current && currentWeight + weight > maximum) {
          lines.push(current);
          current = character;
        } else {
          current += character;
        }
      });
      if (current) lines.push(current);
    });
    return lines.length ? lines : [''];
  }

  function paragraphText(paragraph) {
    var chunks = descendants(paragraph, 't').map(function (node) { return node.textContent || ''; });
    var properties = firstChild(paragraph, 'pPr');
    var bullet = properties && firstDescendant(properties, 'buChar');
    return (bullet ? (bullet.getAttribute('char') || '•') + ' ' : '') + chunks.join('');
  }

  function textStyle(paragraph, theme, defaults) {
    var runProperties = firstDescendant(paragraph, 'rPr') || firstDescendant(paragraph, 'defRPr') || firstDescendant(paragraph, 'endParaRPr');
    var fontSize = runProperties ? numberAttr(runProperties, 'sz', defaults.fontSize / EMU_PER_POINT * 100) / 100 * EMU_PER_POINT : defaults.fontSize;
    var latin = runProperties && firstDescendant(runProperties, 'latin');
    var eastAsian = runProperties && firstDescendant(runProperties, 'ea');
    var paragraphProperties = firstChild(paragraph, 'pPr');
    var requestedFont = (eastAsian && eastAsian.getAttribute('typeface')) || (latin && latin.getAttribute('typeface')) || '';
    return {
      fontSize: Math.max(9 * EMU_PER_POINT, Math.min(96 * EMU_PER_POINT, fontSize || defaults.fontSize)),
      fontFamily: requestedFont ? requestedFont + ', Arial, sans-serif' : defaults.fontFamily,
      color: colorFromNode(runProperties && firstDescendant(runProperties, 'solidFill'), theme, defaults.color),
      bold: runProperties && (runProperties.getAttribute('b') === '1' || runProperties.getAttribute('b') === 'true'),
      italic: runProperties && (runProperties.getAttribute('i') === '1' || runProperties.getAttribute('i') === 'true'),
      align: paragraphProperties ? paragraphProperties.getAttribute('algn') || 'l' : 'l'
    };
  }

  function renderTextBody(shape, box, theme) {
    var body = firstChild(shape, 'txBody');
    if (!body) return '';
    var bodyProperties = firstChild(body, 'bodyPr');
    var left = numberAttr(bodyProperties, 'lIns', 91440);
    var right = numberAttr(bodyProperties, 'rIns', 91440);
    var top = numberAttr(bodyProperties, 'tIns', 45720);
    var bottom = numberAttr(bodyProperties, 'bIns', 45720);
    var availableWidth = Math.max(1, box.width - left - right);
    var paragraphs = childrenNamed(body, 'p');
    var rendered = [];
    var cursor = top;
    var defaults = { fontSize: 18 * EMU_PER_POINT, fontFamily: 'Arial, Microsoft JhengHei, sans-serif', color: theme.tx1 || '#000000' };
    paragraphs.forEach(function (paragraph) {
      var style = textStyle(paragraph, theme, defaults);
      var lines = wrapText(paragraphText(paragraph), availableWidth, style.fontSize);
      var lineHeight = style.fontSize * 1.18;
      var anchor = style.align === 'ctr' ? 'middle' : (style.align === 'r' ? 'end' : 'start');
      var x = style.align === 'ctr' ? box.width / 2 : (style.align === 'r' ? box.width - right : left);
      lines.forEach(function (line) {
        cursor += lineHeight;
        rendered.push('<text x="' + x + '" y="' + cursor + '" text-anchor="' + anchor
          + '" font-family="' + escapeXml(style.fontFamily) + '" font-size="' + style.fontSize
          + '" fill="' + escapeXml(style.color) + '" font-weight="' + (style.bold ? '700' : '400')
          + '" font-style="' + (style.italic ? 'italic' : 'normal') + '">' + escapeXml(line) + '</text>');
      });
      cursor += style.fontSize * 0.18;
    });
    var contentHeight = Math.max(0, cursor - top);
    var anchorMode = bodyProperties ? bodyProperties.getAttribute('anchor') || 't' : 't';
    var translateY = anchorMode === 'ctr' ? Math.max(0, (box.height - top - bottom - contentHeight) / 2) : (anchorMode === 'b' ? Math.max(0, box.height - top - bottom - contentHeight) : 0);
    return '<g transform="translate(' + box.x + ' ' + (box.y + translateY) + ')"' + transformAttribute({ x: 0, y: 0, width: box.width, height: box.height, rotation: box.rotation, flipH: false, flipV: false }) + '>' + rendered.join('') + '</g>';
  }

  function renderShape(shape, theme) {
    var box = transformOf(shape);
    if (!box) return '';
    var properties = firstChild(shape, 'spPr') || shape;
    var fill = shapeFill(properties, theme);
    var line = lineStyle(properties, theme);
    var geometry = firstDescendant(properties, 'prstGeom');
    var preset = geometry ? geometry.getAttribute('prst') || 'rect' : 'rect';
    var common = ' fill="' + fill.color + '" fill-opacity="' + fill.opacity + '" stroke="' + line.color + '" stroke-width="' + line.width + '"'
      + (line.dash ? ' stroke-dasharray="' + line.dash + '"' : '') + transformAttribute(box);
    var graphic;
    if (preset === 'ellipse') {
      graphic = '<ellipse cx="' + (box.x + box.width / 2) + '" cy="' + (box.y + box.height / 2) + '" rx="' + (box.width / 2) + '" ry="' + (box.height / 2) + '"' + common + '/>';
    } else if (/triangle/i.test(preset)) {
      graphic = '<polygon points="' + (box.x + box.width / 2) + ',' + box.y + ' ' + (box.x + box.width) + ',' + (box.y + box.height) + ' ' + box.x + ',' + (box.y + box.height) + '"' + common + '/>';
    } else {
      var radius = /roundRect/i.test(preset) ? Math.min(box.width, box.height) * 0.08 : 0;
      graphic = '<rect x="' + box.x + '" y="' + box.y + '" width="' + box.width + '" height="' + box.height + '" rx="' + radius + '"' + common + '/>';
    }
    return graphic + renderTextBody(shape, box, theme);
  }

  function renderConnector(shape, theme) {
    var box = transformOf(shape);
    if (!box) return '';
    var line = lineStyle(firstChild(shape, 'spPr') || shape, theme);
    var markerStart = line.head && line.head !== 'none' ? ' marker-start="url(#pptx-arrow)"' : '';
    var markerEnd = line.tail && line.tail !== 'none' ? ' marker-end="url(#pptx-arrow)"' : '';
    return '<line x1="' + box.x + '" y1="' + box.y + '" x2="' + (box.x + box.width) + '" y2="' + (box.y + box.height)
      + '" stroke="' + line.color + '" stroke-width="' + line.width + '"' + (line.dash ? ' stroke-dasharray="' + line.dash + '"' : '')
      + markerStart + markerEnd + transformAttribute(box) + '/>';
  }

  function mimeTypeFor(path) {
    var extension = String(path || '').split('.').pop().toLowerCase();
    return { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml', webp: 'image/webp', emf: 'image/emf', wmf: 'image/wmf' }[extension] || '';
  }

  function bytesToBase64(bytes) {
    var binary = '';
    var chunk = 0x8000;
    for (var index = 0; index < bytes.length; index += chunk) {
      binary += String.fromCharCode.apply(null, bytes.subarray(index, Math.min(index + chunk, bytes.length)));
    }
    return btoa(binary);
  }

  async function imageData(documentModel, path) {
    if (documentModel.mediaCache[path]) return documentModel.mediaCache[path];
    var type = mimeTypeFor(path);
    if (!type || type === 'image/emf' || type === 'image/wmf') return '';
    var entry = documentModel.zip.file(path);
    if (!entry) return '';
    var declared = entry._data && Number(entry._data.uncompressedSize);
    if (Number.isFinite(declared) && declared > MAX_MEDIA_BYTES) return '';
    var bytes = await entry.async('uint8array');
    if (bytes.byteLength > MAX_MEDIA_BYTES) return '';
    var data = 'data:' + type + ';base64,' + bytesToBase64(bytes);
    documentModel.mediaCache[path] = data;
    return data;
  }

  async function renderPicture(picture, relationships, documentModel) {
    var box = transformOf(picture);
    var blip = firstDescendant(picture, 'blip');
    var relation = blip && relationships[attributeByLocalName(blip, 'embed')];
    if (!box || !relation || !relation.path) return '';
    var data = await imageData(documentModel, relation.path);
    if (!data) return '';
    return '<image x="' + box.x + '" y="' + box.y + '" width="' + box.width + '" height="' + box.height
      + '" preserveAspectRatio="xMidYMid meet" href="' + escapeXml(data) + '"' + transformAttribute(box) + '/>';
  }

  function cellText(cell) {
    return descendants(cell, 't').map(function (node) { return node.textContent || ''; }).join('');
  }

  function renderTable(frame, theme) {
    var table = firstDescendant(frame, 'tbl');
    var box = transformOf(frame);
    if (!table || !box) return '';
    var columns = descendants(firstChild(table, 'tblGrid'), 'gridCol').map(function (column) { return numberAttr(column, 'w', 1); });
    var columnTotal = columns.reduce(function (sum, width) { return sum + width; }, 0) || 1;
    var rows = childrenNamed(table, 'tr');
    var rowTotal = rows.reduce(function (sum, row) { return sum + numberAttr(row, 'h', 1); }, 0) || 1;
    var output = [];
    var y = box.y;
    rows.forEach(function (row) {
      var rowHeight = box.height * numberAttr(row, 'h', 1) / rowTotal;
      var x = box.x;
      childrenNamed(row, 'tc').forEach(function (cell, index) {
        var width = box.width * (columns[index] || columnTotal / Math.max(1, columns.length)) / columnTotal;
        var fill = shapeFill(firstChild(cell, 'tcPr'), theme);
        output.push('<rect x="' + x + '" y="' + y + '" width="' + width + '" height="' + rowHeight + '" fill="' + (fill.color === 'none' ? '#ffffff' : fill.color) + '" stroke="#9aa9ad" stroke-width="9525"/>');
        var fontSize = Math.min(16 * EMU_PER_POINT, rowHeight * 0.28);
        wrapText(cellText(cell), Math.max(1, width - 100000), fontSize).slice(0, 4).forEach(function (line, lineIndex) {
          output.push('<text x="' + (x + 50000) + '" y="' + (y + fontSize * (1.25 + lineIndex * 1.18)) + '" font-family="Arial, Microsoft JhengHei, sans-serif" font-size="' + fontSize + '" fill="' + theme.tx1 + '">' + escapeXml(line) + '</text>');
        });
        x += width;
      });
      y += rowHeight;
    });
    return output.join('');
  }

  async function renderPart(documentModel, part) {
    var tree = firstDescendant(part.documentNode, 'spTree');
    if (!tree) return '';
    var output = [];
    var nodes = elementChildren(tree);
    for (var index = 0; index < nodes.length; index += 1) {
      var node = nodes[index];
      var type = localName(node);
      if (type === 'sp') output.push(renderShape(node, documentModel.theme));
      if (type === 'cxnSp') output.push(renderConnector(node, documentModel.theme));
      if (type === 'pic') output.push(await renderPicture(node, part.relationships, documentModel));
      if (type === 'graphicFrame') {
        var table = renderTable(node, documentModel.theme);
        if (table) output.push(table);
        else {
          var box = transformOf(node);
          if (box) output.push('<rect x="' + box.x + '" y="' + box.y + '" width="' + box.width + '" height="' + box.height + '" fill="#f3f6f7" stroke="#aab8bc" stroke-width="9525"/><text x="' + (box.x + box.width / 2) + '" y="' + (box.y + box.height / 2) + '" text-anchor="middle" font-size="190500" fill="#63777d">圖表</text>');
        }
      }
      if (type === 'grpSp') {
        var nested = { documentNode: { getElementsByTagName: function () { return []; } }, relationships: part.relationships };
        var groupOutput = [];
        var groupChildren = elementChildren(node);
        for (var nestedIndex = 0; nestedIndex < groupChildren.length; nestedIndex += 1) {
          var child = groupChildren[nestedIndex];
          if (localName(child) === 'sp') groupOutput.push(renderShape(child, documentModel.theme));
          if (localName(child) === 'pic') groupOutput.push(await renderPicture(child, part.relationships, documentModel));
        }
        output.push(groupOutput.join(''));
        void nested;
      }
    }
    return output.join('');
  }

  function backgroundForPart(part, theme) {
    var background = firstDescendant(part.documentNode, 'bg');
    return colorFromNode(background, theme, '');
  }

  async function loadPart(documentModel, path) {
    if (!path || !documentModel.zip.file(path)) return null;
    if (documentModel.partCache[path]) return documentModel.partCache[path];
    var part = {
      path: path,
      documentNode: await readXml(documentModel.zip, path, true),
      relationships: await readRelationships(documentModel.zip, path)
    };
    documentModel.partCache[path] = part;
    return part;
  }

  async function linkedPart(documentModel, part, typeSuffix) {
    var keys = Object.keys(part.relationships);
    for (var index = 0; index < keys.length; index += 1) {
      var relationship = part.relationships[keys[index]];
      if (relationship.type.slice(-typeSuffix.length) === typeSuffix) return loadPart(documentModel, relationship.path);
    }
    return null;
  }

  function PptxRenderTask(executor) {
    var self = this;
    this.cancelled = false;
    this.image = null;
    this.promise = Promise.resolve().then(function () { return executor(self); });
  }
  PptxRenderTask.prototype.cancel = function () {
    this.cancelled = true;
    if (this.image) this.image.src = '';
  };

  function cancelledError() {
    var error = new Error('Rendering cancelled');
    error.name = 'RenderingCancelledException';
    return error;
  }

  function extractCanvasText(svg) {
    var documentNode = parseXml(svg, 'rendered slide');
    var commands = descendants(documentNode, 'text').map(function (node) {
      var translateX = 0;
      var translateY = 0;
      var rotation = 0;
      var parent = node.parentNode;
      if (parent && parent.getAttribute) {
        var transform = parent.getAttribute('transform') || '';
        var translate = transform.match(/translate\(\s*(-?[\d.]+)(?:[ ,]+)(-?[\d.]+)\s*\)/);
        var rotate = transform.match(/rotate\(\s*(-?[\d.]+)/);
        if (translate) {
          translateX = Number(translate[1]) || 0;
          translateY = Number(translate[2]) || 0;
        }
        if (rotate) rotation = Number(rotate[1]) || 0;
      }
      var command = {
        text: node.textContent || '',
        x: translateX + numberAttr(node, 'x', 0),
        y: translateY + numberAttr(node, 'y', 0),
        rotation: rotation,
        fontSize: Math.max(1, numberAttr(node, 'font-size', 18 * EMU_PER_POINT)),
        fontFamily: node.getAttribute('font-family') || 'Arial, Microsoft JhengHei, sans-serif',
        color: node.getAttribute('fill') || '#000000',
        bold: node.getAttribute('font-weight') === '700' || node.getAttribute('font-weight') === 'bold',
        italic: node.getAttribute('font-style') === 'italic',
        anchor: node.getAttribute('text-anchor') || 'start'
      };
      if (node.parentNode) node.parentNode.removeChild(node);
      return command;
    });
    return { svg: new XMLSerializer().serializeToString(documentNode), commands: commands };
  }

  function drawCanvasText(context, commands, documentModel) {
    var scaleX = context.canvas.width / documentModel.width;
    var scaleY = context.canvas.height / documentModel.height;
    commands.forEach(function (command) {
      if (!command.text) return;
      context.save();
      context.fillStyle = command.color;
      context.textAlign = command.anchor === 'middle' ? 'center' : (command.anchor === 'end' ? 'right' : 'left');
      context.textBaseline = 'alphabetic';
      context.font = (command.italic ? 'italic ' : '') + (command.bold ? '700 ' : '400 ')
        + Math.max(1, command.fontSize * scaleY) + 'px ' + command.fontFamily;
      context.translate(command.x * scaleX, command.y * scaleY);
      if (command.rotation) context.rotate(command.rotation * Math.PI / 180);
      context.fillText(command.text, 0, 0);
      context.restore();
    });
  }

  function PptxPage(documentModel, pageNumber) {
    this.documentModel = documentModel;
    this.pageNumber = pageNumber;
  }
  PptxPage.prototype.getViewport = function (options) {
    var scale = Number(options && options.scale) || 1;
    return { width: this.documentModel.width / EMU_PER_PIXEL * scale, height: this.documentModel.height / EMU_PER_PIXEL * scale, scale: scale };
  };
  PptxPage.prototype.render = function (options) {
    var self = this;
    return new PptxRenderTask(async function (task) {
      var context = options.canvasContext;
      var canvas = context && context.canvas;
      if (!canvas) fail('瀏覽器無法建立投影片畫布。');
      var extracted = extractCanvasText(await self.documentModel.slideSvg(self.pageNumber));
      var svg = extracted.svg;
      if (task.cancelled) throw cancelledError();
      var blobUrl = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
      try {
        var image = new Image();
        task.image = image;
        image.decoding = 'async';
        await new Promise(function (resolve, reject) {
          image.onload = resolve;
          image.onerror = function () { reject(new RendererError('投影片 SVG 無法轉成畫布。')); };
          image.src = blobUrl;
        });
        if (task.cancelled) throw cancelledError();
        context.save();
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        drawCanvasText(context, extracted.commands, self.documentModel);
        context.restore();
      } finally {
        task.image = null;
        URL.revokeObjectURL(blobUrl);
      }
    });
  };

  function PptxDocument(zip, slidePaths, width, height, theme) {
    this.zip = zip;
    this.slidePaths = slidePaths;
    this.width = width;
    this.height = height;
    this.theme = theme;
    this.numPages = slidePaths.length;
    this.mediaCache = {};
    this.partCache = {};
    this.svgCache = {};
  }
  PptxDocument.prototype.getPage = function (pageNumber) {
    var page = Number(pageNumber);
    if (!Number.isInteger(page) || page < 1 || page > this.numPages) return Promise.reject(new RendererError('投影片頁次無效。'));
    return Promise.resolve(new PptxPage(this, page));
  };
  PptxDocument.prototype.slideSvg = async function (pageNumber) {
    if (this.svgCache[pageNumber]) return this.svgCache[pageNumber];
    var slide = await loadPart(this, this.slidePaths[pageNumber - 1]);
    var layout = await linkedPart(this, slide, '/slideLayout');
    var master = layout ? await linkedPart(this, layout, '/slideMaster') : null;
    var parts = [master, layout, slide].filter(Boolean);
    var background = '#ffffff';
    parts.forEach(function (part) { background = backgroundForPart(part, this.theme) || background; }, this);
    var content = '';
    for (var index = 0; index < parts.length; index += 1) content += await renderPart(this, parts[index]);
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' + (this.width / EMU_PER_PIXEL) + '" height="' + (this.height / EMU_PER_PIXEL)
      + '" viewBox="0 0 ' + this.width + ' ' + this.height + '"><defs><marker id="pptx-arrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="context-stroke"/></marker></defs>'
      + '<rect width="100%" height="100%" fill="' + escapeXml(background) + '"/>' + content + '</svg>';
    this.svgCache[pageNumber] = svg;
    return svg;
  };

  PptxDocument.load = async function (arrayBuffer, JSZipConstructor) {
    if (!(arrayBuffer instanceof ArrayBuffer) || arrayBuffer.byteLength < 64 || arrayBuffer.byteLength > MAX_ARCHIVE_BYTES) {
      fail('PPTX 大小無效或超過 120 MB 的瀏覽器預覽上限。');
    }
    if (!JSZipConstructor || typeof JSZipConstructor.loadAsync !== 'function') fail('JSZip 尚未載入。');
    var zip;
    try {
      zip = await JSZipConstructor.loadAsync(arrayBuffer, { checkCRC32: false, createFolders: false });
    } catch (error) {
      fail('PPTX 封裝無法解壓縮。');
    }
    var entries = Object.keys(zip.files);
    if (entries.length < 3 || entries.length > MAX_ENTRIES) fail('PPTX 封裝檔案數量超過安全限制。');
    var presentationPath = 'ppt/presentation.xml';
    var presentation = await readXml(zip, presentationPath, true);
    var relationships = await readRelationships(zip, presentationPath);
    var size = firstDescendant(presentation, 'sldSz');
    var width = Math.max(1, numberAttr(size, 'cx', 12192000));
    var height = Math.max(1, numberAttr(size, 'cy', 6858000));
    var slidePaths = [];
    descendants(presentation, 'sldId').forEach(function (slideId) {
      var relation = relationships[relationshipId(slideId)];
      if (relation && relation.path && zip.file(relation.path)) slidePaths.push(relation.path);
    });
    if (!slidePaths.length) {
      slidePaths = entries.filter(function (path) { return /^ppt\/slides\/slide\d+\.xml$/i.test(path); }).sort(function (left, right) {
        return Number(left.match(/slide(\d+)/i)[1]) - Number(right.match(/slide(\d+)/i)[1]);
      });
    }
    if (!slidePaths.length || slidePaths.length > 1000) fail('PPTX 不含可預覽的投影片，或頁數超過上限。');
    var themeDocument = await readXml(zip, 'ppt/theme/theme1.xml', false);
    return new PptxDocument(zip, slidePaths, width, height, parseTheme(themeDocument));
  };

  global.TWWaterPptx = Object.freeze({
    version: '1.0.0',
    PptxDocument: PptxDocument,
    RendererError: RendererError
  });
})(window);
