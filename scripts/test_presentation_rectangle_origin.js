'use strict';

const assert = require('assert');
const fs = require('fs');

(async () => {
  const sourcePath = 'C:/web/gary/TWWATER/assets/presentation.js';
  const source = fs.readFileSync(sourcePath, 'utf8');
  const boxFactory = source.match(/PresentationReview\.prototype\.createBoxObject[\s\S]*?\n  };/);
  assert.ok(boxFactory, 'createBoxObject implementation was not found.');
  assert.match(boxFactory[0], /originX:\s*'left'/, 'Rectangle must use the pointer as its left origin.');
  assert.match(boxFactory[0], /originY:\s*'top'/, 'Rectangle must use the pointer as its top origin.');
  assert.match(boxFactory[0], /left:\s*left\s*,/, 'Rectangle outer left edge must equal the pointer x coordinate.');
  assert.match(boxFactory[0], /top:\s*top\s*,/, 'Rectangle outer top edge must equal the pointer y coordinate.');
  assert.match(boxFactory[0], /width:\s*Math\.max\(1,\s*width\s*-\s*strokeWidth\)/, 'Rectangle outer width must end at the drag pointer.');
  assert.match(boxFactory[0], /height:\s*Math\.max\(1,\s*height\s*-\s*strokeWidth\)/, 'Rectangle outer height must end at the drag pointer.');

  const moveHandler = source.match(/PresentationReview\.prototype\.handleCanvasPointerMove[\s\S]*?\n  };/);
  assert.ok(moveHandler, 'handleCanvasPointerMove implementation was not found.');
  assert.match(moveHandler[0], /var strokeWidth = Number\(this\.drawing\.object\.strokeWidth \|\| 0\)/, 'Drag updates must account for the visible stroke width.');
  assert.match(moveHandler[0], /left:\s*Math\.min\(start\.x, point\.x\)\s*,/, 'Drag preview left edge must remain at mouse-down.');
  assert.match(moveHandler[0], /top:\s*Math\.min\(start\.y, point\.y\)\s*,/, 'Drag preview top edge must remain at mouse-down.');
  assert.match(moveHandler[0], /Math\.abs\(point\.x - start\.x\)\s*-\s*strokeWidth/, 'Drag preview right edge must follow the pointer.');
  assert.match(moveHandler[0], /Math\.abs\(point\.y - start\.y\)\s*-\s*strokeWidth/, 'Drag preview bottom edge must follow the pointer.');

  const fabric = await import('file:///C:/web/gary/TWWATER/assets/vendor/fabric/index.min.mjs');
  const strokeWidth = 3;
  const drags = [
    [{ x: 599, y: 289 }, { x: 818, y: 365 }],
    [{ x: 818, y: 365 }, { x: 599, y: 289 }],
    [{ x: 599, y: 365 }, { x: 818, y: 289 }],
    [{ x: 818, y: 289 }, { x: 599, y: 365 }],
  ];
  drags.forEach(([start, end]) => {
    const expectedLeft = Math.min(start.x, end.x);
    const expectedTop = Math.min(start.y, end.y);
    const expectedRight = Math.max(start.x, end.x);
    const expectedBottom = Math.max(start.y, end.y);
    const rect = new fabric.Rect({
      left: expectedLeft,
      top: expectedTop,
      width: Math.abs(end.x - start.x) - strokeWidth,
      height: Math.abs(end.y - start.y) - strokeWidth,
      stroke: '#d83b3b',
      strokeWidth,
      fill: 'rgba(255,255,255,0)',
      originX: 'left',
      originY: 'top',
      strokeUniform: true,
    });
    const bounds = rect.getBoundingRect();
    assert.ok(Math.abs(bounds.left - expectedLeft) < 1e-9, 'Visual left edge drifted from the drag bounds.');
    assert.ok(Math.abs(bounds.top - expectedTop) < 1e-9, 'Visual top edge drifted from the drag bounds.');
    assert.ok(Math.abs((bounds.left + bounds.width) - expectedRight) < 1e-9, 'Visual right edge did not follow the pointer.');
    assert.ok(Math.abs((bounds.top + bounds.height) - expectedBottom) < 1e-9, 'Visual bottom edge did not follow the pointer.');

    const restored = new fabric.Rect({
      left: bounds.left,
      top: bounds.top,
      width: bounds.width - strokeWidth,
      height: bounds.height - strokeWidth,
      stroke: '#d83b3b',
      strokeWidth,
      fill: 'rgba(255,255,255,0)',
      originX: 'left',
      originY: 'top',
      strokeUniform: true,
    }).getBoundingRect();
    assert.deepStrictEqual(restored, bounds, 'Saved and restored rectangle bounds must not drift.');
  });

  console.log('[OK] Presentation rectangle origin contract passed.');
})().catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
