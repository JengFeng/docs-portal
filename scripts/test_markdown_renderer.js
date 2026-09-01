'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const rendererPath = 'C:/web/gary/TWWATER/assets/markdown-renderer.js';
assert.ok(fs.existsSync(rendererPath), 'Markdown renderer asset must exist.');

global.window = global;
vm.runInThisContext(fs.readFileSync(rendererPath, 'utf8'), { filename: rendererPath });
assert.ok(global.TWWaterMarkdown, 'Markdown renderer must publish TWWaterMarkdown.');
assert.strictEqual(typeof global.TWWaterMarkdown.MarkdownDocument.load, 'function');

(async () => {
  const source = '# 標題\n\n這是一份可在線檢視的 Markdown。\n\n- 項目一\n- 項目二\n\n```js\nconst safe = true;\n```';
  const document = await global.TWWaterMarkdown.MarkdownDocument.load(source);
  assert.strictEqual(document.numPages, 1, 'Markdown review uses one immutable annotation surface.');
  const page = await document.getPage(1);
  const viewport = page.getViewport({ scale: 1 });
  assert.ok(viewport.width >= 900 && viewport.height >= 700, 'Markdown viewport must be readable.');
  assert.ok(document.layout.some((entry) => entry.kind === 'heading'), 'Heading semantics must be preserved.');
  assert.ok(document.layout.some((entry) => entry.kind === 'code'), 'Code blocks must be preserved.');
  await assert.rejects(document.getPage(2), /頁次無效/);
  console.log('[OK] Markdown renderer contract passed.');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
