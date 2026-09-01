import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync(new URL('../assets/image-library.js',import.meta.url),'utf8');
const context={window:{},document:{querySelectorAll:()=>[]},console,URL,Blob,setTimeout,clearTimeout};
vm.createContext(context);
vm.runInContext(source,context,{filename:'image-library.js'});

const lifecycle=context.window.TWImageTransformLifecycle;
assert.ok(lifecycle&&typeof lifecycle.release==='function','IMAGE REVIEW must expose a deterministic Fabric transform release guard');

const calls=[];
const canvas={_currentTransform:{target:{isMoving:true}},endCurrentTransform(event){calls.push(event);this._currentTransform=null;}};
const pointerUp={type:'pointerup',buttons:0};
assert.equal(lifecycle.release(canvas,pointerUp),true,'pointerup must end an active Fabric transform');
assert.equal(calls.length,1);
assert.equal(canvas._currentTransform,null);
assert.equal(lifecycle.release(canvas,pointerUp),false,'a completed transform must not be finalized twice');
assert.equal(calls.length,1);

const missedReleaseCanvas={_currentTransform:{target:{isMoving:true}},endCurrentTransform(event){calls.push(event);this._currentTransform=null;}};
assert.equal(lifecycle.releaseIfButtonsUp(missedReleaseCanvas,{type:'pointermove',buttons:0}),true,'buttons=0 pointermove must recover a missed pointerup');
assert.equal(missedReleaseCanvas._currentTransform,null);

const draggingCanvas={_currentTransform:{target:{isMoving:true}},endCurrentTransform(){throw new Error('must not finish while primary button is still down');}};
assert.equal(lifecycle.releaseIfButtonsUp(draggingCanvas,{type:'pointermove',buttons:1}),false);

console.log('[OK] IMAGE REVIEW ends active Fabric transforms on release and recovers missed mouseup.');
