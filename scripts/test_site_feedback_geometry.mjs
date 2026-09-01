import assert from 'node:assert/strict';
import { normalizeBox, normalizeArrow, geometryToPixels, geometryToViewportPixels, distanceToSegment, clampPoint, markerBoxAt, translateViewportPixels, numberOrdinal } from '../assets/site-feedback-geometry.mjs';

const sizes = [[1200, 800], [900, 700], [390, 844]];
for (const [width, height] of sizes) {
  const xA=width*.15, xB=width*.8, yA=height*.2, yB=height*.75;
  for (const [start, end] of [
    [{x:xA,y:yA},{x:xB,y:yB}], [{x:xB,y:yB},{x:xA,y:yA}],
    [{x:xA,y:yB},{x:xB,y:yA}], [{x:xB,y:yA},{x:xA,y:yB}],
  ]) {
    const normalized = normalizeBox(start, end, width, height);
    assert.ok(normalized.x >= 0 && normalized.y >= 0 && normalized.x + normalized.width <= 1.000000001 && normalized.y + normalized.height <= 1.000000001);
    const pixels = geometryToPixels('rectangle', normalized, width, height);
    assert.ok(Math.abs(pixels.left - Math.min(start.x,end.x)) < 1e-8);
    assert.ok(Math.abs(pixels.top - Math.min(start.y,end.y)) < 1e-8);
    assert.ok(Math.abs(pixels.width - Math.abs(end.x-start.x)) < 1e-8);
    assert.ok(Math.abs(pixels.height - Math.abs(end.y-start.y)) < 1e-8);
  }
  const arrow = normalizeArrow({x:width*.8,y:height*.75},{x:width*.15,y:height*.2},width,height);
  const restored = geometryToPixels('arrow',arrow,width,height);
  assert.ok(Math.abs(restored.x1-width*.8)<1e-8 && Math.abs(restored.y1-height*.75)<1e-8);
  assert.ok(Math.abs(restored.x2-width*.15)<1e-8 && Math.abs(restored.y2-height*.2)<1e-8);
}
assert.deepEqual(clampPoint({x:-10,y:900},390,844),{x:0,y:844});
assert.throws(()=>normalizeBox({x:1,y:1},{x:1,y:1},390,844),/too small/i);

const saved={width:1200,height:800,scroll_x:40,scroll_y:600};
const current={width:900,height:700,scroll_x:140,scroll_y:720};
assert.deepEqual(geometryToViewportPixels('rectangle',{x:.25,y:.5,width:.2,height:.1},saved,current),{left:200,top:280,width:240,height:80});
assert.deepEqual(geometryToViewportPixels('arrow',{x1:.1,y1:.2,x2:.9,y2:.8},saved,current),{x1:20,y1:40,x2:980,y2:520});
assert.ok(distanceToSegment({x:500,y:281},{x:100,y:280},{x:900,y:280})<2,'middle of a long arrow must be selectable');
assert.ok(distanceToSegment({x:500,y:340},{x:100,y:280},{x:900,y:280})>50,'points far from arrow shaft must not hit');
assert.deepEqual(markerBoxAt({x:50,y:40},100,80,40),{x:.3,y:.25,width:.4,height:.5},'number markers must be fixed circular points rather than drag-sized boxes');
assert.deepEqual(translateViewportPixels('rectangle',{left:10,top:20,width:30,height:40},{x:100,y:-50},120,100),{left:90,top:0,width:30,height:40},'boxes must move while remaining fully visible');
assert.deepEqual(translateViewportPixels('arrow',{x1:10,y1:20,x2:70,y2:80},{x:80,y:40},100,100),{x1:40,y1:40,x2:100,y2:100},'arrows must translate as one object while preserving endpoints');
const annotations=[{public_id:'box',annotation_type_code:'rectangle'},{public_id:'n1',annotation_type_code:'number'},{public_id:'arrow',annotation_type_code:'arrow'},{public_id:'n2',annotation_type_code:'number'}];
assert.equal(numberOrdinal(annotations,'n1'),1);assert.equal(numberOrdinal(annotations,'n2'),2,'number sequence must count number markers only, independent of action order');
console.log('[OK] site feedback pointer geometry is directional, movable, numbered independently, document-anchored, bounded, selectable, and viewport-stable.');
