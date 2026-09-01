import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync(new URL('../assets/image-library.js',import.meta.url),'utf8');
const context={window:{},document:{querySelectorAll:()=>[]},console,URL,Blob,setTimeout,clearTimeout};
vm.createContext(context);
vm.runInContext(source,context,{filename:'image-library.js'});
const builder=context.window.TWImageRequirementBuilder;
assert.ok(builder&&typeof builder.build==='function','pure image requirement builder must be exported for deterministic verification');

const documentIdentity={publicId:'11111111-1111-4111-8111-111111111111',title:'供水資訊圖',relativePath:'圖片/供水資訊圖.png',sourceHash:'a'.repeat(64)};
const annotations=[
 {publicId:'22222222-2222-4222-8222-222222222222',saved:true,statusCode:'open',annotationType:'arrow',note:'箭頭改指向右上方',x:.1,y:.2,width:.3,height:.4,geometry:{x1:.1,y1:.2,x2:.4,y2:.6},style:{stroke:'#d83b3b',strokeWidth:3}},
 {publicId:'33333333-3333-4333-8333-333333333333',saved:true,statusCode:'open',annotationType:'text',note:'文字改為穩定供水',x:.2,y:.3,width:.25,height:.08,geometry:{text:'穩定供水'},style:{textColor:'#112233',fontSize:28}},
 {publicId:'44444444-4444-4444-8444-444444444444',saved:true,statusCode:'resolved',annotationType:'rectangle',note:'已完成，不應匯出',x:.4,y:.4,width:.1,height:.1,geometry:{},style:{}},
 {publicId:'',saved:false,statusCode:'open',annotationType:'number',note:'尚未儲存，不應匯出',x:.5,y:.5,width:.05,height:.07,geometry:{number:7},style:{}}
];
const built=builder.build(documentIdentity,annotations);
assert.equal(built.payload.schemaVersion,'twwater-image-change-request-v2');
assert.deepEqual(JSON.parse(JSON.stringify(built.payload.document)),documentIdentity);
assert.equal(built.payload.annotations.length,2,'only saved open annotations are exported');
assert.deepEqual(JSON.parse(JSON.stringify(built.payload.annotations[0].geometry)),{x1:.1,y1:.2,x2:.4,y2:.6});
assert.deepEqual(JSON.parse(JSON.stringify(built.payload.annotations[0].style)),{stroke:'#d83b3b',strokeWidth:3});
for(const token of [documentIdentity.publicId,annotations[0].publicId,'type=arrow','"x1":0.1','"x2":0.4','"stroke":"#d83b3b"',annotations[1].publicId,'type=text','"text":"穩定供水"','先封存目前正式原圖','原子替換同一路徑','定位資料衝突時停止並回報']) assert.ok(built.prompt.includes(token),`prompt missing ${token}`);
assert.equal(built.payload.safety.archiveBeforeAutomaticReplace,true);
assert.equal(built.payload.safety.restoreArchivesCurrentFirst,true);
assert.equal(builder.eligible(false,annotations),true);
assert.equal(builder.eligible(true,annotations),false,'dirty local state must disable export');
assert.equal(builder.eligible(false,annotations.map(item=>({...item,statusCode:'resolved'}))),false,'zero saved-open state must disable export');
const json=builder.serialize(built.payload);assert.deepEqual(JSON.parse(json).annotations,JSON.parse(JSON.stringify(built.payload.annotations)));

const collaboration=context.window.TWImageRevisionCollaboration;
assert.ok(collaboration,'immutable revision collaboration helper must be exported');
const revisionOne={public_id:'55555555-5555-4555-8555-555555555555',revision_number:1,source_content_hash:'a'.repeat(64),status_code:'ready',archived_at:null,item_count:2,requirement_json:json,prompt_text:built.prompt,created_at:'2026-08-29T08:00:00'};
const revisionOld={...revisionOne,public_id:'66666666-6666-4666-8666-666666666666',revision_number:2,source_content_hash:'b'.repeat(64),status_code:'completed',completed_at:'2026-08-29T09:00:00'};
const initial=collaboration.initialState();
assert.equal(collaboration.canCopy(initial),false,'buttons fail closed before initialization');
let state=collaboration.initialize([revisionOne,revisionOld],documentIdentity.sourceHash);
assert.equal(state.selectedRevision.public_id,revisionOne.public_id,'latest nonarchived revision for current source is initially selected');
const selectedOutput=collaboration.output(state);
annotations[0].note='UNSAVED MUTATION MUST NOT LEAK';
assert.equal(collaboration.output(state).prompt,selectedOutput.prompt,'current unsaved annotations cannot alter selected immutable revision output');
state=collaboration.setDirty(state,true);
assert.equal(collaboration.canCopy(state),true,'dirty state still permits copying an existing stored revision');
assert.equal(collaboration.canCreate(state),false,'dirty state blocks creating a new revision');
state=collaboration.select(state,revisionOld.public_id);
assert.equal(state.selectedRevision.public_id,revisionOld.public_id);
assert.equal(collaboration.isCurrentSource(state),false,'older source revision must not be mistaken for current');
assert.deepEqual(collaboration.filter([revisionOne,{...revisionOld,archived_at:'2026-08-29T10:00:00'}],false).map(r=>r.public_id),[revisionOne.public_id]);
assert.deepEqual(collaboration.filter([revisionOne,{...revisionOld,archived_at:'2026-08-29T10:00:00'}],true).map(r=>r.public_id),[revisionOld.public_id]);
assert.equal(collaboration.canComplete(state),false,'completed revision cannot complete again in UI');
const historicalReady={...revisionOld,status_code:'ready',completed_at:null};
state=collaboration.initialize([revisionOne,historicalReady],documentIdentity.sourceHash);
state=collaboration.select(state,historicalReady.public_id);
assert.equal(collaboration.canComplete(state),false,'a ready revision for a historical image source cannot complete the current image workflow');
assert.equal(collaboration.currentCompleted(state),false,'historical completed state cannot lock the current source');
const completedCurrent={...revisionOne,status_code:'completed',completed_at:'2026-08-29T10:00:00'};
state=collaboration.initialize([completedCurrent,historicalReady],documentIdentity.sourceHash);
assert.equal(collaboration.currentCompleted(state),true,'nonarchived completion for the current source reconstructs the lock after reload');
assert.equal(collaboration.canArchive(state,completedCurrent),false,'the current-source completed revision cannot be archived to unlock the editor');
assert.equal(collaboration.canArchive(state,historicalReady),true,'unrelated history revisions remain archivable during a completed workflow');
assert.equal(JSON.parse(collaboration.output(state).json).document.sourceHash,'a'.repeat(64),'download uses stored JSON verbatim even when selected history source differs from current');
console.log('[OK] executable IMAGE REVIEW immutable revision projection, dirty-state, source identity and archive filtering passed.');
