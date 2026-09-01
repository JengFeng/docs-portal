(() => {
  'use strict';
  const TOOLS = ['browse','select','rectangle','arrow','highlight','text','number'];
  const LABELS = { rectangle:'框選', arrow:'箭頭', highlight:'螢光', text:'文字', number:'編號' };
  const clone = (value) => JSON.parse(JSON.stringify(value));
  const clamp = (value, min=0, max=1) => Math.max(min, Math.min(max, Number(value) || 0));
  function buildImageRequirement(documentIdentity,annotations) {
    const saved=annotations.filter((item) => item.saved && item.statusCode === 'open');
    const items=saved.map((item,index)=>({
      order:index+1,annotationId:String(item.publicId||''),type:String(item.annotationType||'rectangle'),typeLabel:LABELS[item.annotationType]||'框選',instruction:String(item.note||''),
      position:{x:item.x,y:item.y,width:item.width,height:item.height},geometry:clone(item.geometry||{}),style:clone(item.style||{})
    }));
    const payload={
      schemaVersion:'twwater-image-change-request-v2',taskKind:'image-modification',
      document:{publicId:String(documentIdentity.publicId||''),title:String(documentIdentity.title||''),relativePath:String(documentIdentity.relativePath||''),sourceHash:String(documentIdentity.sourceHash||'')},
      annotations:items,
      safety:{archiveBeforeAutomaticReplace:true,restoreArchivesCurrentFirst:true,stopOnLocationConflict:true,noExternalImageApiWithoutApproval:true}
    };
    const lines=[
      '請依下列已儲存的圖片標註修改指定圖片。',
      `文件 ID：${payload.document.publicId}`,
      `圖片：${payload.document.title}`,
      `相對路徑：${payload.document.relativePath}`,
      `來源 SHA-256：${payload.document.sourceHash}`,'',
      ...items.map((item)=>`${item.order}. ${item.typeLabel}；annotation_id=${item.annotationId}；type=${item.type}\n   修改：${item.instruction}\n   position=${JSON.stringify(item.position)}\n   geometry=${JSON.stringify(item.geometry)}\n   style=${JSON.stringify(item.style)}`),
      '',
      '安全界線：AI修改結果通過來源SHA-256、格式、尺寸與ROI驗證後，必須先封存目前正式原圖，再原子替換同一路徑；還原前也必須先封存當下版本。不得自行呼叫外部圖片 API；定位資料衝突時停止並回報。',
      '完成時請提供修改結果、逐項對照、封存版本與網站呈現驗證。'
    ];
    return {payload,prompt:lines.join('\n')};
  }
  window.TWImageRequirementBuilder={
    build:buildImageRequirement,
    eligible:(hasUnsavedChanges,annotations)=>!hasUnsavedChanges&&annotations.some((item)=>item.saved&&item.statusCode==='open'),
    serialize:(payload)=>JSON.stringify(payload,null,2)
  };
  const revisionCollaboration={
    initialState:()=>({initialized:false,revisions:[],selectedRevision:null,currentSource:'',dirty:false}),
    filter:(revisions,archived)=>revisions.filter((revision)=>Boolean(revision.archived_at)===Boolean(archived)).slice().sort((a,b)=>Number(b.revision_number)-Number(a.revision_number)||String(b.created_at||'').localeCompare(String(a.created_at||''))),
    initialize:(revisions,currentSource)=>{const all=Array.isArray(revisions)?revisions.map(clone):[];const current=all.filter((revision)=>!revision.archived_at&&String(revision.source_content_hash)===String(currentSource)).sort((a,b)=>Number(b.revision_number)-Number(a.revision_number))[0]||null;return {initialized:true,revisions:all,selectedRevision:current,currentSource:String(currentSource||''),dirty:false};},
    select:(state,id)=>({...state,selectedRevision:state.revisions.find((revision)=>String(revision.public_id)===String(id))||null}),
    setDirty:(state,dirty)=>({...state,dirty:Boolean(dirty)}),
    canCopy:(state)=>Boolean(state.initialized&&state.selectedRevision),
    canCreate:(state)=>Boolean(state.initialized&&!state.dirty),
    canComplete:(state)=>Boolean(state.initialized&&state.selectedRevision&&!state.selectedRevision.archived_at&&state.selectedRevision.status_code==='ready'&&String(state.selectedRevision.source_content_hash)===String(state.currentSource)),
    canArchive:(state,revision)=>Boolean(revision&&!(revision.status_code==='completed'&&!revision.archived_at&&String(revision.source_content_hash)===String(state.currentSource))),
    currentCompleted:(state)=>Boolean(state.initialized&&state.revisions.some((revision)=>!revision.archived_at&&revision.status_code==='completed'&&String(revision.source_content_hash)===String(state.currentSource))),
    isCurrentSource:(state)=>Boolean(state.selectedRevision&&String(state.selectedRevision.source_content_hash)===state.currentSource),
    output:(state)=>{if(!revisionCollaboration.canCopy(state))throw new Error('REVISION_NOT_SELECTED');return {prompt:String(state.selectedRevision.prompt_text||''),json:String(state.selectedRevision.requirement_json||'')};}
  };
  window.TWImageRevisionCollaboration=revisionCollaboration;
  const workflowState={
    derive:(source={})=>{
      const busy=Boolean(source.revisionBusy),completionLocked=Boolean(source.completionLocked),locked=busy||completionLocked;
      return {busy,locked,completionLocked,lockedLabel:completionLocked?'修改已完成，修改功能已鎖定':'',showSpinner:busy,organizeLabel:busy?'整理中…':'整理精確規格'};
    }
  };
  window.TWImageWorkflowState=workflowState;
  const transformLifecycle={
    release:(canvas,event)=>{if(!canvas?._currentTransform||typeof canvas.endCurrentTransform!=='function')return false;canvas.endCurrentTransform(event);return true;},
    releaseIfButtonsUp:(canvas,event)=>Number(event?.buttons)===0?transformLifecycle.release(canvas,event):false
  };
  window.TWImageTransformLifecycle=transformLifecycle;

  document.querySelectorAll('[data-image-library]').forEach((library) => {
    const form = library.querySelector('[data-image-export-form]');
    if (!form) return;
    const cards = () => Array.from(form.querySelectorAll('.image-asset-card'));
    const selected = () => cards().filter((card) => card.querySelector('[data-image-export-item]')?.checked);
    const count = form.querySelector('[data-image-selected-count]');
    const selectAll = form.querySelector('[data-select-all-images]');
    const update = () => { if (count) count.textContent = `已選 ${selected().length} 張`; if (selectAll) selectAll.checked = cards().length > 0 && selected().length === cards().length; };
    form.addEventListener('change', update);
    selectAll?.addEventListener('change', () => { cards().forEach((card) => { const box = card.querySelector('[data-image-export-item]'); if (box) box.checked = selectAll.checked; }); update(); });
    form.addEventListener('click', (event) => {
      const button = event.target.closest('[data-image-move-up],[data-image-move-down]'); if (!button) return;
      const card = button.closest('.image-asset-card'); if (!card) return;
      if (button.hasAttribute('data-image-move-up') && card.previousElementSibling) card.parentNode.insertBefore(card, card.previousElementSibling);
      if (button.hasAttribute('data-image-move-down') && card.nextElementSibling) card.parentNode.insertBefore(card.nextElementSibling, card);
    });
    form.addEventListener('submit', (event) => { if (!selected().length) { event.preventDefault(); window.alert('請至少選取一張圖片。'); } });
    update();
  });

  async function initReview(root) {
    const geometryHelper=window.TWImageGeometry;
    if(!geometryHelper) throw new Error('圖片標註座標元件尚未載入。');
    const image = root.querySelector('[data-image-source]');
    const canvasElement = root.querySelector('[data-image-canvas]');
    const stage = root.querySelector('[data-image-stage]');
    const scroller = root.querySelector('.image-stage-scroll');
    const list = root.querySelector('[data-image-annotation-list]');
    const detailList = root.querySelector('[data-image-detail-list]');
    const detailDialog = root.querySelector('[data-image-detail-dialog]');
    const detailOpen = root.querySelector('[data-image-detail-open]');
    const summaryTitle = root.querySelector('[data-image-summary-title]');
    const resultEmpty = root.querySelector('[data-image-result-empty]');
    const save = root.querySelector('[data-image-save]');
    const status = root.querySelector('[data-image-review-status]');
    const zoomLabel = root.querySelector('[data-image-zoom-label]');
    const userId = String(root.dataset.userId || '');
    const canAnnotate = root.dataset.canAnnotate === '1';
    const canManageAll = root.dataset.canManageAll === '1';
    const createRevision = root.querySelector('[data-image-revision-create]');
    const newRequirement = document.querySelector('[data-image-new-request]');
    const stageStatus = root.querySelector('[data-image-stage-status]');
    const copyHermesPrompt = root.querySelector('[data-image-revision-copy]');
    const downloadRequirements = root.querySelector('[data-image-revision-download]');
    const completeRevision = root.querySelector('[data-image-revision-complete]');
    const reviewPage = root.closest('.image-review-page') || root;

    const historyRevision = document.querySelector('[data-image-revision-history]');
    const historyDialog = root.querySelector('[data-image-revision-history-dialog]');
    const historyList = root.querySelector('[data-image-revision-history-list]');
    const historyStatus = root.querySelector('[data-image-revision-history-status]');
    const selectedRevisionStatus = root.querySelector('[data-image-selected-revision-status]');
    const revisionIdentity = root.querySelector('[data-image-revision-identity]');
    const requirementStatus = root.querySelector('[data-image-requirement-status]');
    const requirementPreview = root.querySelector('[data-image-requirement-preview]');
    const detailRequirementPreview = root.querySelector('[data-image-detail-preview]');
    const detailRevisionIdentity = root.querySelector('[data-image-detail-revision-identity]');
    let annotations = [];
    try { annotations = JSON.parse(root.dataset.annotations || '[]').map(normalize); } catch (_) { annotations = []; }
    let revisions=[];try{revisions=JSON.parse(root.dataset.revisions||'[]');}catch(_){revisions=[];}
    let revisionState={...revisionCollaboration.initialize(revisions,root.dataset.sourceHash||''),selectedRevision:null},historyArchived=false,imageView='draft';
    let currentTool = 'browse', fabricCanvas = null, fabric = null, drawing = null, zoom = 1, selectedIndex = -1, hasUnsavedChanges = false,revisionBusy=false,historyMutationBusy=false,completionFeedbackTimer=0;
    const undoStack = [], redoStack = [];
    const currentWorkflow=()=>workflowState.derive({revisionBusy:revisionBusy||historyMutationBusy,completionLocked:revisionCollaboration.currentCompleted(revisionState)});
    const workflowLocked=()=>currentWorkflow().locked;
    const workflowBusy=()=>currentWorkflow().busy;
    function projectCompletionLock(control,locked,label){if(!control)return;control.classList.toggle('is-completion-locked',locked);if(locked)control.title=label;else if(control.title===label)control.removeAttribute('title');}
    function applyWorkflowState(){
      const flow=currentWorkflow();root.setAttribute('aria-busy',flow.busy?'true':'false');root.classList.toggle('is-completion-locked',flow.completionLocked);if(flow.locked&&drawing)cancelDrawing();
      if(createRevision){createRevision.disabled=flow.locked||!revisionCollaboration.canCreate(revisionState)||!window.TWImageRequirementBuilder.eligible(false,annotations);createRevision.textContent='';if(flow.showSpinner){const spinner=document.createElement('span');spinner.className='site-feedback-spinner is-button';spinner.setAttribute('aria-hidden','true');createRevision.appendChild(spinner);}createRevision.appendChild(document.createTextNode(flow.organizeLabel));}
      if(save)save.disabled=flow.locked||!canAnnotate;if(newRequirement)newRequirement.disabled=flow.locked||!canAnnotate;
      root.querySelectorAll('[data-image-tool]').forEach((button)=>{button.disabled=flow.locked||(!canAnnotate&&!['browse','select'].includes(button.dataset.imageTool));});
      root.querySelectorAll('[data-image-action]').forEach((button)=>{button.disabled=flow.locked;});
      if(copyHermesPrompt)copyHermesPrompt.disabled=flow.busy||!revisionCollaboration.canCopy(revisionState);if(downloadRequirements)downloadRequirements.disabled=flow.busy||!revisionCollaboration.canCopy(revisionState);
      if(completeRevision){const canComplete=revisionCollaboration.canComplete(revisionState);completeRevision.hidden=false;completeRevision.disabled=flow.busy||flow.completionLocked;completeRevision.textContent=flow.completionLocked?'已完成並鎖定':(canComplete?'確認修改已完成':'先選擇要完成的 Revision');}
      if(detailOpen)detailOpen.textContent=flow.completionLocked?'檢視標註細節':'檢視／修改細部內容';
      root.querySelectorAll('[data-image-history-action]').forEach((button)=>{button.disabled=flow.busy||button.dataset.imageCompletionOwner==='1'||button.dataset.imageUnavailable==='1';projectCompletionLock(button,flow.completionLocked&&button.dataset.imageCompletionOwner==='1',flow.lockedLabel);});
      root.querySelectorAll('[data-image-workflow-mutation]').forEach((button)=>{button.disabled=flow.locked;});
      [newRequirement,save,createRevision,completeRevision,...root.querySelectorAll('[data-image-tool],[data-image-action],[data-image-workflow-mutation]')].forEach((control)=>projectCompletionLock(control,flow.completionLocked,flow.lockedLabel));
      if(fabricCanvas){if(flow.locked)fabricCanvas.discardActiveObject();fabricCanvas.selection=!flow.locked&&currentTool==='select'&&canAnnotate;fabricCanvas.skipTargetFind=flow.locked||currentTool!=='select';fabricCanvas.getObjects().forEach((object)=>object.set({selectable:!flow.locked&&canAnnotate&&ownedOpen(annotations[Number(object.__annotationIndex)]||{}),evented:!flow.locked}));fabricCanvas.requestRenderAll();}
      updateHistory();
      root.querySelectorAll('[data-image-annotation-list] textarea,[data-image-detail-list] textarea').forEach((field)=>{const key=String(field.closest('li')?.dataset.itemId||'');const item=annotations.find((entry,index)=>String(entry.publicId||index)===key);field.disabled=flow.locked||!item||!ownedOpen(item);});

    }

    function normalize(source) {
      const geometry = source.geometry && typeof source.geometry === 'object' ? source.geometry : {};
      const style = source.style && typeof source.style === 'object' ? source.style : {};
      return {
        publicId: String(source.public_id || ''), owner: String(source.created_by_user_id || ''), displayName: String(source.display_name || ''),
        statusCode: String(source.status_code || 'open'), createdAt: String(source.created_at || ''),
        annotationType: TOOLS.includes(String(source.annotation_type_code || source.annotation_type)) ? String(source.annotation_type_code || source.annotation_type) : 'rectangle',
        x: clamp(source.x_norm ?? source.x), y: clamp(source.y_norm ?? source.y), width: clamp(source.width_norm ?? source.width, .0001), height: clamp(source.height_norm ?? source.height, .0001),
        note: String(source.note_text || source.note || '').slice(0,1000), geometry, style, saved: Boolean(source.public_id)
      };
    }
    const ownedOpen = (item) => item.owner === userId && item.statusCode === 'open';
    const snapshot = () => clone(annotations);
    function remember() { undoStack.push(snapshot()); if (undoStack.length > 100) undoStack.shift(); redoStack.length = 0; updateHistory(); dirty(); }
    function restore(value) { annotations = clone(value); selectedIndex = -1; drawAll(); renderList(); updateHistory(); dirty(); }
    function updateHistory() {
      const undo = root.querySelector('[data-image-action="undo"]'), redo = root.querySelector('[data-image-action="redo"]'), del = root.querySelector('[data-image-action="delete-selection"]');
      if (undo) undo.disabled = workflowLocked() || !canAnnotate || !undoStack.length; if (redo) redo.disabled = workflowLocked() || !canAnnotate || !redoStack.length;
      if (del) del.disabled = workflowLocked() || !canAnnotate || selectedIndex < 0 || !ownedOpen(annotations[selectedIndex] || {});
    }
    function buildHermesRequirement() {
      return window.TWImageRequirementBuilder.build({
        publicId:root.dataset.documentId,title:root.dataset.documentTitle,relativePath:root.dataset.relativePath,sourceHash:root.dataset.sourceHash
      },annotations);
    }
    function updateRequirementPreview() {
      revisionState=revisionCollaboration.setDirty(revisionState,hasUnsavedChanges);
      const selected=revisionState.selectedRevision,copyReady=revisionCollaboration.canCopy(revisionState),canComplete=revisionCollaboration.canComplete(revisionState),prompt=copyReady?revisionCollaboration.output(revisionState).prompt:'';
      if(requirementPreview)requirementPreview.value=prompt;if(detailRequirementPreview)detailRequirementPreview.value=prompt;
      if(copyHermesPrompt){copyHermesPrompt.disabled=workflowBusy()||!copyReady;copyHermesPrompt.textContent=copyReady?'複製完整修改指令':'請先整理精確規格';}
      if(downloadRequirements)downloadRequirements.disabled=workflowBusy()||!copyReady;
      if(createRevision)createRevision.disabled=workflowLocked()||!revisionCollaboration.canCreate(revisionState)||!window.TWImageRequirementBuilder.eligible(false,annotations);
      if(selectedRevisionStatus)selectedRevisionStatus.textContent=selected?(selected.archived_at?'已封存':(selected.status_code==='completed'?'修改已完成':'規格已整理，可直接協作')):'編輯中';
      const identity=selected?`Revision ${selected.revision_number}・來源 SHA-256：${selected.source_content_hash}（${revisionCollaboration.isCurrentSource(revisionState)?'目前圖片版本':'歷史圖片版本'}）`:'尚未整理精確規格';
      if(revisionIdentity)revisionIdentity.textContent=identity;if(detailRevisionIdentity)detailRevisionIdentity.textContent=identity;applyWorkflowState();
    }
    function dirty(message='有未儲存變更') { hasUnsavedChanges=true; if (status) status.textContent = message; if(requirementStatus) requirementStatus.textContent='尚有未儲存變更；仍可使用既有 Revision，但請先儲存才能整理新 Revision。'; updateRequirementPreview(); }

    const moduleUrl = new URL(root.dataset.fabricModuleUrl || 'assets/vendor/fabric/index.min.mjs', document.baseURI);
    if (moduleUrl.origin !== location.origin) throw new Error('Fabric.js 必須由本站載入。');
    const imported = await import(moduleUrl.href);
    fabric = [imported, imported.fabric, imported.default].find((candidate) => candidate && typeof candidate.Canvas === 'function');
    if (!fabric) throw new Error('圖片標註元件尚未載入。');
    fabricCanvas = new fabric.Canvas(canvasElement,{selection:false,preserveObjectStacking:true,allowTouchScrolling:true});
    const refreshCanvasOffset = () => { if (fabricCanvas) fabricCanvas.calcOffset(); };
    scroller?.addEventListener('scroll',refreshCanvasOffset,{passive:true});
    window.addEventListener('scroll',refreshCanvasOffset,{passive:true});
    window.addEventListener('resize',refreshCanvasOffset,{passive:true});

    function styleFor(item) {
      const defaults = item.annotationType === 'highlight'
        ? { stroke:'#d99b00', fill:'#ffd84d', opacity:.35, strokeWidth:2, textColor:'#0b2a3b', fontSize:22 }
        : { stroke:'#d83b3b', fill:'rgba(255,255,255,0)', opacity:1, strokeWidth:3, textColor:'#d83b3b', fontSize:22 };
      return Object.assign(defaults, item.style || {});
    }
    function px(item) { return { left:item.x*fabricCanvas.getWidth(), top:item.y*fabricCanvas.getHeight(), width:item.width*fabricCanvas.getWidth(), height:item.height*fabricCanvas.getHeight() }; }
    function common(item,index) { return { __annotationIndex:index, originX:'left', originY:'top', selectable:canAnnotate&&ownedOpen(item), evented:true, lockRotation:true, lockScalingFlip:true, transparentCorners:false, cornerColor:'#0d8fa8', borderColor:'#0d8fa8' }; }
    function objectFor(item,index) {
      const box=px(item), style=styleFor(item), shared=common(item,index);
      if (item.annotationType === 'arrow' || item.annotationType === 'text' || item.annotationType === 'number') {
        return geometryHelper.fabricObjectFor(fabric,{...item,style},index,{width:fabricCanvas.getWidth(),height:fabricCanvas.getHeight()},shared);
      }
      const rectBox=geometryHelper.rectObjectBox(item,{width:fabricCanvas.getWidth(),height:fabricCanvas.getHeight()},style.strokeWidth);
      return new fabric.Rect(Object.assign({left:rectBox.left,top:rectBox.top,width:rectBox.width,height:rectBox.height,fill:style.fill,stroke:style.stroke,strokeWidth:style.strokeWidth,opacity:style.opacity},shared));
    }
    function drawAll() {
      fabricCanvas.discardActiveObject(); fabricCanvas.clear(); annotations.forEach((item,index) => fabricCanvas.add(objectFor(item,index))); fabricCanvas.requestRenderAll(); updateHistory();
    }
    function syncObject(object) {
      const index=Number(object.__annotationIndex); if (!Number.isInteger(index)||!annotations[index]||!ownedOpen(annotations[index])) return;
      const item=annotations[index];
      if(item.annotationType==='arrow'||item.annotationType==='text'||item.annotationType==='number') Object.assign(item,geometryHelper.annotationFromFabricObject(fabric,object,item,{width:fabricCanvas.getWidth(),height:fabricCanvas.getHeight()}));
      else Object.assign(item,geometryHelper.annotationFromOuter(object.getBoundingRect(),{width:fabricCanvas.getWidth(),height:fabricCanvas.getHeight()}));
    }
    fabricCanvas.on('selection:created',(e)=>{selectedIndex=Number(e.selected?.[0]?.__annotationIndex??-1);renderList();updateHistory();});
    fabricCanvas.on('selection:updated',(e)=>{selectedIndex=Number(e.selected?.[0]?.__annotationIndex??-1);renderList();updateHistory();});
    fabricCanvas.on('selection:cleared',()=>{selectedIndex=-1;renderList();updateHistory();});
    fabricCanvas.on('object:modified',(e)=>{if(workflowLocked())return;remember();syncObject(e.target);renderList();});

    function point(event) { const pointer=typeof fabricCanvas.getScenePoint==='function'?fabricCanvas.getScenePoint(event.e):fabricCanvas.getPointer(event.e); return {x:clamp(pointer.x/fabricCanvas.getWidth()),y:clamp(pointer.y/fabricCanvas.getHeight())}; }
    function cancelDrawing(){if(drawing?.preview)fabricCanvas.remove(drawing.preview);drawing=null;fabricCanvas.requestRenderAll();}
    let transformReleaseTimer=0;
    const scheduleTransformRelease=(event,buttonsMustBeUp=false)=>{if(!root.isConnected)return;window.clearTimeout(transformReleaseTimer);transformReleaseTimer=window.setTimeout(()=>{if(!root.isConnected)return;if(buttonsMustBeUp)transformLifecycle.releaseIfButtonsUp(fabricCanvas,event);else transformLifecycle.release(fabricCanvas,event);},0);};
    fabricCanvas.upperCanvasEl.addEventListener('pointercancel',(event)=>{cancelDrawing();scheduleTransformRelease(event);});
    fabricCanvas.upperCanvasEl.addEventListener('touchcancel',(event)=>{cancelDrawing();scheduleTransformRelease(event);});
    window.addEventListener('pointerup',(event)=>scheduleTransformRelease(event));
    window.addEventListener('mouseup',(event)=>scheduleTransformRelease(event));
    window.addEventListener('pointercancel',(event)=>scheduleTransformRelease(event));
    window.addEventListener('pointermove',(event)=>{if(Number(event.buttons)===0)scheduleTransformRelease(event,true);});
    function draftAnnotation(tool,start,end){
      if(tool==='text'||tool==='number'){
        const box=geometryHelper.pointAnnotationBox(end,tool==='text'?{width:.25,height:.08}:{width:.05,height:.07});
        return normalize({created_by_user_id:userId,annotation_type:tool,...box,note:'',geometry:tool==='text'?{text:'輸入文字'}:{number:annotations.filter((item)=>item.annotationType==='number').length+1},style:{stroke:'#d83b3b',textColor:'#d83b3b',fontSize:22}});
      }
      const box=geometryHelper.normalizeDrag(start,end),geometry={...box};if(tool==='arrow')Object.assign(geometry,{x1:start.x,y1:start.y,x2:end.x,y2:end.y});
      return normalize({created_by_user_id:userId,annotation_type:tool,...box,note:'',geometry,style:tool==='highlight'?{stroke:'#d99b00',fill:'#ffd84d',opacity:.35}:{stroke:'#d83b3b',strokeWidth:3}});
    }
    function renderDrawingPreview(end){
      if(!drawing)return;if(drawing.preview)fabricCanvas.remove(drawing.preview);const item=draftAnnotation(drawing.tool,drawing.start,end),preview=objectFor(item,-1);preview.set({selectable:false,evented:false,opacity:drawing.tool==='highlight'?.35:.7});drawing.preview=preview;fabricCanvas.add(preview);fabricCanvas.requestRenderAll();
    }
    fabricCanvas.on('mouse:down',(event)=>{
      if(workflowLocked()||!canAnnotate||currentTool==='select'||currentTool==='browse') return;
      const start=point(event);drawing={tool:currentTool,start,preview:null};fabricCanvas.selection=false;renderDrawingPreview(start);
    });
    fabricCanvas.on('mouse:move',(event)=>{if(workflowLocked()){if(drawing)cancelDrawing();return;}if(drawing)renderDrawingPreview(point(event));});
    fabricCanvas.on('mouse:up',(event)=>{
      if(workflowLocked()){if(drawing)cancelDrawing();return;}if(!drawing)return;const end=point(event),tool=drawing.tool,start=drawing.start,item=draftAnnotation(tool,start,end);cancelDrawing();
      const tooSmall=tool==='arrow'?Math.hypot(item.geometry.x2-item.geometry.x1,item.geometry.y2-item.geometry.y1)<.005:(!['text','number'].includes(tool)&&(item.width<.005||item.height<.005));if(tooSmall)return;
      remember();annotations.push(item);selectedIndex=annotations.length-1;setTool('select');drawAll();renderList();dirty('標註已建立，請在右側填寫修改說明後儲存。');
      const textarea=list.querySelector('li:last-child textarea');if(textarea)textarea.focus({preventScroll:true});
    });
    function setTool(tool) { if(workflowLocked()||!TOOLS.includes(tool)||(!canAnnotate&&!['browse','select'].includes(tool))) return;if(drawing)cancelDrawing();currentTool=tool;root.querySelectorAll('[data-image-tool]').forEach((button)=>button.setAttribute('aria-pressed',button.dataset.imageTool===tool?'true':'false'));fabricCanvas.selection=tool==='select'&&canAnnotate;fabricCanvas.skipTargetFind=tool!=='select';fabricCanvas.defaultCursor=tool==='browse'?'grab':(tool==='select'?'default':'crosshair');const touchAction=tool==='browse'?'pan-x pan-y':'none';fabricCanvas.allowTouchScrolling=tool==='browse';stage.style.touchAction=touchAction;fabricCanvas.wrapperEl.style.touchAction=touchAction;fabricCanvas.upperCanvasEl.style.touchAction=touchAction;fabricCanvas.lowerCanvasEl.style.touchAction=touchAction;if(stageStatus)stageStatus.textContent=tool==='browse'?'現在可瀏覽與捲動圖片；按「新增修改需求」或選擇標註工具後即可圈選位置。':(tool==='select'?'點選任一框選、箭頭、螢光、文字或編號並直接拖曳，即可修正位置。':`正在使用「${LABELS[tool]||'框選'}」；請在圖片上拖曳建立修改位置。`); }
    root.querySelectorAll('[data-image-tool]').forEach((button)=>{button.disabled=!canAnnotate&&!['browse','select'].includes(button.dataset.imageTool);button.addEventListener('click',()=>setTool(button.dataset.imageTool));});
    if(newRequirement){newRequirement.disabled=!canAnnotate;newRequirement.addEventListener('click',()=>{if(workflowLocked()||!canAnnotate)return;setImageView('draft');setTool('rectangle');stage.scrollIntoView({behavior:'smooth',block:'center'});fabricCanvas.discardActiveObject();fabricCanvas.requestRenderAll();});}
    function setImageView(view){imageView=view==='result'?'result':'draft';const result=imageView==='result';root.querySelectorAll('[data-image-view]').forEach((control)=>control.classList.toggle('is-active',control.dataset.imageView===imageView));if(fabricCanvas?.wrapperEl)fabricCanvas.wrapperEl.hidden=result;list.hidden=result;if(resultEmpty)resultEmpty.hidden=true;if(summaryTitle)summaryTitle.textContent=result?'目前正式圖片':'修改說明（可直接填寫）';const toolGroup=root.querySelector('[data-image-tool]')?.closest('.site-feedback-toolbar-group'),editGroup=root.querySelector('[data-image-action="undo"]')?.closest('.site-feedback-toolbar-group');if(toolGroup)toolGroup.hidden=result;if(editGroup)editGroup.hidden=result;if(stageStatus)stageStatus.textContent=result?'修改結果：目前正式圖片已直接呈現；被替換的原圖可於下方「圖片版本與還原」檢視或還原。':'修改內容：中央是目前正式圖片；框線與文字是保存的修改指引。';}
    root.querySelectorAll('[data-image-view]').forEach((control)=>control.addEventListener('click',(event)=>{event.preventDefault();setImageView(control.dataset.imageView);}));
    detailOpen?.addEventListener('click',()=>{renderList();updateRequirementPreview();if(detailDialog&&!detailDialog.open)detailDialog.showModal();});
    root.querySelector('[data-image-detail-close]')?.addEventListener('click',()=>detailDialog?.close());
    detailDialog?.addEventListener('click',(event)=>{if(event.target===detailDialog)detailDialog.close();});

    function removeSelection() { if(workflowLocked()||selectedIndex<0||!ownedOpen(annotations[selectedIndex]||{})) return; remember(); annotations.splice(selectedIndex,1); selectedIndex=-1; drawAll(); renderList(); }
    root.querySelectorAll('[data-image-action]').forEach((button)=>button.addEventListener('click',()=>{
      if(workflowLocked())return;const action=button.dataset.imageAction;
      if(action==='undo'&&undoStack.length){redoStack.push(snapshot());restore(undoStack.pop());}
      if(action==='redo'&&redoStack.length){undoStack.push(snapshot());restore(redoStack.pop());}
      if(action==='delete-selection')removeSelection();
      if(action==='zoom-in')setZoom(zoom+.15); if(action==='zoom-out')setZoom(zoom-.15); if(action==='zoom-reset')fitWidth();
    }));
    document.addEventListener('keydown',(event)=>{if(!root.isConnected)return;const tag=document.activeElement?.tagName;if(tag==='INPUT'||tag==='TEXTAREA')return;if(event.key==='Delete'){event.preventDefault();removeSelection();}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='z'){event.preventDefault();if(event.shiftKey)root.querySelector('[data-image-action="redo"]')?.click();else root.querySelector('[data-image-action="undo"]')?.click();}if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='y'){event.preventDefault();root.querySelector('[data-image-action="redo"]')?.click();}});

    function renderListInto(container,detailed) {
      if(!container)return;container.textContent='';
      if(!annotations.length){const li=document.createElement('li');li.className='site-feedback-empty';li.textContent='此批次尚無修改說明。請先在中央圖片建立標註。';container.appendChild(li);return;}
      annotations.forEach((item,index)=>{const li=document.createElement('li');li.className=`${detailed?'site-feedback-annotation-item ':''}${item.statusCode==='resolved'?'resolved ':''}${selectedIndex===index?'is-selected selected':''}`.trim();li.dataset.itemId=String(item.publicId||index);if(selectedIndex===index)li.setAttribute('aria-current','true');
        const heading=document.createElement('div');heading.className=detailed?'site-feedback-annotation-heading':'';const strong=document.createElement(detailed?'button':'strong');if(detailed){strong.type='button';strong.className='site-feedback-item-select';}strong.textContent=`${index+1}. ${LABELS[item.annotationType]||'框選'}`;heading.appendChild(strong);if(detailed){const badge=document.createElement('small');badge.textContent=item.statusCode==='resolved'?'已處理':'待處理';heading.appendChild(badge);}li.appendChild(heading);
        const note=document.createElement('textarea');note.className=detailed?'':'site-feedback-inline-instruction';note.maxLength=1000;note.placeholder=item.annotationType==='text'?'輸入文字內容或這個位置要修改什麼。':'直接寫這個位置要修改什麼。';note.value=item.note;note.disabled=!ownedOpen(item);note.addEventListener('input',()=>{if(workflowLocked()||!ownedOpen(item))return;item.note=note.value.slice(0,1000);if(item.annotationType==='text')item.geometry={...(item.geometry||{}),text:item.note||'輸入文字'};dirty();});note.addEventListener('change',()=>{if(workflowLocked())return;if(item.annotationType==='text')drawAll();});li.appendChild(note);
        const selectObject=()=>{const object=fabricCanvas.getObjects().find((candidate)=>Number(candidate.__annotationIndex)===index);if(object){fabricCanvas.setActiveObject(object);fabricCanvas.requestRenderAll();selectedIndex=index;renderList();updateHistory();}};if(detailed)strong.addEventListener('click',selectObject);
        if(detailed&&item.publicId&&(canManageAll||ownedOpen(item))){const actions=document.createElement('div');actions.className='image-annotation-actions';const toggle=document.createElement('button');toggle.type='button';toggle.className='secondary-button';toggle.dataset.imageWorkflowMutation='status';toggle.textContent=item.statusCode==='resolved'?'重新開啟':'標記已處理';const mutationFlow=currentWorkflow();toggle.disabled=mutationFlow.locked;projectCompletionLock(toggle,mutationFlow.completionLocked,mutationFlow.lockedLabel);toggle.addEventListener('click',async()=>{if(workflowLocked())return;toggle.disabled=true;try{const response=await fetch(root.dataset.statusUrl||'',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrfToken||'',Accept:'application/json'},body:JSON.stringify({annotation_id:item.publicId,status_code:item.statusCode==='resolved'?'open':'resolved',source_hash:root.dataset.sourceHash||''})});const payload=await response.json();if(!response.ok||payload.ok!==true)throw new Error();item.statusCode=String(payload.status_code||item.statusCode);drawAll();renderList();updateRequirementPreview();}catch(_){toggle.disabled=false;dirty('狀態更新失敗，請重新整理後再試。');}});actions.appendChild(toggle);li.appendChild(actions);}container.appendChild(li);});
    }
    function renderList(){renderListInto(list,false);renderListInto(detailList,true);}

    function resizeCanvas() { const width=Math.max(1,image.clientWidth),height=Math.max(1,image.clientHeight);fabricCanvas.setDimensions({width,height});refreshCanvasOffset();drawAll(); }
    function setZoom(value){zoom=Math.max(.25,Math.min(3,value));image.style.width=`${Math.round(image.naturalWidth*zoom)}px`;image.style.maxWidth='none';if(zoomLabel)zoomLabel.textContent=`${Math.round(zoom*100)}%`;requestAnimationFrame(resizeCanvas);}
    function fitWidth(){const available=Math.max(280,(scroller?.clientWidth||stage.parentElement.clientWidth)-32);setZoom(Math.min(1,available/image.naturalWidth));}
    if(!image.complete)await new Promise((resolve,reject)=>{image.addEventListener('load',resolve,{once:true});image.addEventListener('error',reject,{once:true});});
    fitWidth(); new ResizeObserver(()=>{if(image.naturalWidth&&Math.abs(image.clientWidth-image.naturalWidth*zoom)<3)resizeCanvas();}).observe(scroller||stage);

    async function revisionApi(url,body){const response=await fetch(url||'',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrfToken||'',Accept:'application/json'},body:JSON.stringify(body)});const payload=await response.json();if(!response.ok||payload.ok!==true)throw new Error(payload.error||'REQUEST_FAILED');return payload;}
    function downloadSelectedRevision(){if(!revisionCollaboration.canCopy(revisionState))return;const output=revisionCollaboration.output(revisionState),parsed=JSON.parse(output.json),blob=new Blob([JSON.stringify(parsed,null,2)],{type:'application/json;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a'),selected=revisionState.selectedRevision;link.href=url;link.download=`${String(root.dataset.documentTitle||'image-change-request').replace(/[\\/:*?"<>|]+/g,'-').slice(0,80)}-Revision-${selected.revision_number}.json`;document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),0);}
    function renderRevisionHistoryLegacy(){if(!historyList)return;historyList.textContent='';const visible=revisionCollaboration.filter(revisionState.revisions,historyArchived);root.querySelectorAll('[data-image-history-view]').forEach((button)=>button.classList.toggle('is-active',(button.dataset.imageHistoryView==='archived')===historyArchived));if(!visible.length){const empty=document.createElement('p');empty.className='image-revision-history-empty';empty.textContent=historyArchived?'目前沒有封存資料。':'目前沒有修改歷程。';historyList.appendChild(empty);return;}visible.forEach((revision)=>{const row=document.createElement('article');row.className='image-revision-history-row';const heading=document.createElement('div');const title=document.createElement('strong');title.textContent=`Revision ${revision.revision_number}`;const state=document.createElement('span');state.textContent=revision.status_code==='completed'?'已完成':'可協作';heading.append(title,state);row.appendChild(heading);const meta=document.createElement('p');meta.textContent=`${revision.item_count} 項需求・${String(revision.created_at||'').replace('T',' ').slice(0,16)}・來源 ${String(revision.source_content_hash||'').slice(0,12)}…${String(revision.source_content_hash)===String(root.dataset.sourceHash)?'（目前版本）':'（歷史版本）'}`;row.appendChild(meta);const actions=document.createElement('div');actions.className='image-revision-history-actions';[['檢視／選取','select'],['複製提示詞','copy'],['下載 JSON','download'],[historyArchived?'解除封存':'封存',historyArchived?'restore':'archive']].forEach(([label,action])=>{const button=document.createElement('button');button.type='button';button.className='secondary-button';button.textContent=label;button.addEventListener('click',async()=>{revisionState=revisionCollaboration.select(revisionState,revision.public_id);updateRequirementPreview();if(action==='select'){historyDialog?.close();return;}if(action==='copy'){await navigator.clipboard.writeText(revisionCollaboration.output(revisionState).prompt);return;}if(action==='download'){downloadSelectedRevision();return;}button.disabled=true;try{await revisionApi(root.dataset.revisionArchiveUrl,{revision_id:revision.public_id,restore:action==='restore'});revision.archived_at=action==='restore'?null:new Date().toISOString();revisionState=revisionCollaboration.select(revisionState,revision.public_id);renderRevisionHistory();updateRequirementPreview();}catch(_){button.disabled=false;if(requirementStatus)requirementStatus.textContent='封存狀態更新失敗。';}});actions.appendChild(button);});row.appendChild(actions);historyList.appendChild(row);});}
    function setHistoryFeedback(message,tone='info'){if(!historyStatus)return;historyStatus.textContent=message;historyStatus.dataset.tone=tone;}
    function openRevisionGuidance(){renderRevisionHistory();setHistoryFeedback(revisionState.revisions.length?'請按歷程中的「選取此 Revision」，再確認修改完成。':'目前沒有可選的 Revision；請先儲存修改內容並整理精確規格。','guidance');if(historyDialog&&!historyDialog.open)historyDialog.showModal();window.requestAnimationFrame(()=>historyList?.querySelector('[data-image-history-action="select"]')?.focus());}
    function renderRevisionHistory(){
      if(!historyList)return;historyList.textContent='';const visible=revisionCollaboration.filter(revisionState.revisions,historyArchived).slice().sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||'')));
      root.querySelectorAll('[data-image-history-view]').forEach((control)=>control.classList.toggle('is-active',(control.dataset.imageHistoryView==='archived')===historyArchived));
      if(!visible.length){const empty=document.createElement('p');empty.className='site-feedback-empty';empty.textContent=historyArchived?'目前沒有封存資料。':'目前沒有修改歷程。';historyList.appendChild(empty);return;}
      visible.forEach((revision,index)=>{const selected=String(revisionState.selectedRevision?.public_id||'')===String(revision.public_id),row=document.createElement('details');row.className=`site-feedback-batch-list-row${selected?' is-selected':''}${historyArchived?' is-archived':''}`;row.open=selected||(!revisionState.selectedRevision&&index===0);
        const summary=document.createElement('summary');summary.className='site-feedback-batch-list-summary';const sequence=document.createElement('span');sequence.className='site-feedback-batch-sequence';sequence.textContent=String(index+1).padStart(2,'0');const title=document.createElement('strong');title.textContent='AI 圖像／資訊圖表畫面調整';const time=document.createElement('time');time.dateTime=String(revision.created_at||'');time.textContent=String(revision.created_at||'').replace('T',' ').slice(0,16)||'—';const state=document.createElement('span');state.className='site-feedback-status';state.textContent=historyArchived?'已封存':(revision.status_code==='completed'?'修改已完成':'規格已整理，可直接協作');summary.append(sequence,title,time,state);row.appendChild(summary);
        const detail=document.createElement('div');detail.className='site-feedback-batch-list-detail';const identity=document.createElement('div');identity.className='site-feedback-batch-identity';const idLabel=document.createElement('span');idLabel.textContent='Revision ID';const code=document.createElement('code');code.textContent=String(revision.public_id);identity.append(idLabel,code);detail.appendChild(identity);const meta=document.createElement('p');meta.className='site-feedback-batch-meta';meta.textContent=`Revision ${revision.revision_number}・${revision.item_count} 項需求${historyArchived?'・原狀態：'+(revision.status_code==='completed'?'修改已完成':'規格已整理，可直接協作'):''}`;detail.appendChild(meta);
        const actions=document.createElement('div');actions.className='site-feedback-batch-row-actions';const selectButton=document.createElement('button');selectButton.type='button';selectButton.className='primary-button';selectButton.dataset.imageHistoryAction='select';selectButton.textContent=selected?'已選取此 Revision':'選取此 Revision';selectButton.disabled=workflowBusy();selectButton.addEventListener('click',(event)=>{event.stopPropagation();if(workflowBusy())return;revisionState=revisionCollaboration.select(revisionState,revision.public_id);updateRequirementPreview();setHistoryFeedback(`已選取 Revision ${revision.revision_number}；現在可確認修改完成。`,'success');historyDialog?.close();completeRevision?.focus();});actions.appendChild(selectButton);[['無修改前截圖','before'],['無修改後截圖','after'],['複製 ID','copy-id'],[historyArchived?'解除封存':'封存',historyArchived?'restore':'archive']].forEach(([label,action])=>{const button=document.createElement('button');button.type='button';button.className=`secondary-button${action==='archive'||action==='restore'?' site-feedback-archive-button':''}`;button.dataset.imageHistoryAction=action;button.textContent=label;const completionOwner=action==='archive'&&!revisionCollaboration.canArchive(revisionState,revision);if(completionOwner){button.dataset.imageCompletionOwner='1';button.disabled=true;projectCompletionLock(button,true,currentWorkflow().lockedLabel);}if(action==='before'||action==='after'){button.dataset.imageUnavailable='1';button.disabled=true;button.setAttribute('aria-disabled','true');button.title='此 Revision 未保存獨立不可變前後截圖，因此無法開啟。';}else if(action==='copy-id')button.addEventListener('click',async(event)=>{event.stopPropagation();try{await navigator.clipboard.writeText(String(revision.public_id));setHistoryFeedback(`已複製 Revision ID：${revision.public_id}`,'success');}catch(_){setHistoryFeedback('無法存取剪貼簿；請手動選取上方 Revision ID。','error');}});else button.addEventListener('click',async(event)=>{event.stopPropagation();if(workflowBusy()||(action==='archive'&&!revisionCollaboration.canArchive(revisionState,revision)))return;const originalLabel=button.textContent;historyMutationBusy=true;applyWorkflowState();button.textContent=action==='restore'?'解除封存中…':'封存中…';setHistoryFeedback(action==='restore'?`正在解除封存 Revision ${revision.revision_number}…`:`正在封存 Revision ${revision.revision_number}…`,'busy');try{await revisionApi(root.dataset.revisionArchiveUrl,{revision_id:revision.public_id,restore:action==='restore'});revision.archived_at=action==='restore'?null:new Date().toISOString();historyMutationBusy=false;renderRevisionHistory();updateRequirementPreview();setHistoryFeedback(action==='restore'?`已解除封存 Revision ${revision.revision_number}。`:`已封存 Revision ${revision.revision_number}。`,'success');}catch(_){historyMutationBusy=false;button.textContent=originalLabel;applyWorkflowState();setHistoryFeedback('封存狀態更新失敗，請重新整理後再試。','error');}});actions.appendChild(button);});detail.appendChild(actions);row.appendChild(detail);historyList.appendChild(row);});
    }
    createRevision?.addEventListener('click',async()=>{if(workflowLocked()||!revisionCollaboration.canCreate(revisionState)||!window.TWImageRequirementBuilder.eligible(false,annotations))return;revisionBusy=true;applyWorkflowState();if(requirementStatus)requirementStatus.textContent='正在整理精確規格，完成前已鎖定編輯操作。';try{const payload=await revisionApi(root.dataset.revisionCreateUrl,{document_id:root.dataset.documentId,source_hash:root.dataset.sourceHash});revisionState={...revisionState,revisions:[payload.revision,...revisionState.revisions],selectedRevision:payload.revision};if(requirementStatus)requirementStatus.textContent=`已建立 Revision ${payload.revision.revision_number}，複製與下載固定使用此不可變版本；Hermes修改通過驗證後會自動封存並更新正式圖片。`;renderRevisionHistory();}catch(_){if(requirementStatus)requirementStatus.textContent='無法整理修改需求，請確認已有已儲存的待處理標註。';}finally{revisionBusy=false;updateRequirementPreview();}});
    copyHermesPrompt?.addEventListener('click',async()=>{if(workflowBusy()||!revisionCollaboration.canCopy(revisionState))return;try{await navigator.clipboard.writeText(revisionCollaboration.output(revisionState).prompt);if(requirementStatus)requirementStatus.textContent=`已複製 Revision ${revisionState.selectedRevision.revision_number} AI 修改提示詞。`;}catch(_){if(requirementStatus)requirementStatus.textContent='無法存取剪貼簿，可展開技術細節手動複製。';}});
    downloadRequirements?.addEventListener('click',()=>{if(workflowBusy()||!revisionCollaboration.canCopy(revisionState))return;downloadSelectedRevision();if(requirementStatus)requirementStatus.textContent=`已下載 Revision ${revisionState.selectedRevision.revision_number} 修改需求 JSON。`;});
    completeRevision?.addEventListener('click',async()=>{if(workflowBusy())return;if(!revisionCollaboration.canComplete(revisionState)){openRevisionGuidance();return;}const completedRevisionId=String(revisionState.selectedRevision.public_id);revisionBusy=true;applyWorkflowState();if(requirementStatus)requirementStatus.textContent='正在確認修改完成，完成前已暫時鎖定修改操作。';try{await revisionApi(root.dataset.revisionStatusUrl,{revision_id:completedRevisionId});const completedRevision=revisionState.revisions.find((revision)=>String(revision.public_id)===completedRevisionId);if(!completedRevision)throw new Error('REVISION_NOT_FOUND');completedRevision.status_code='completed';completedRevision.completed_at=new Date().toISOString();revisionBusy=false;reviewPage.classList.add('is-completion-feedback');window.clearTimeout(completionFeedbackTimer);completionFeedbackTimer=window.setTimeout(()=>reviewPage.classList.remove('is-completion-feedback'),1800);updateRequirementPreview();renderRevisionHistory();if(requirementStatus)requirementStatus.textContent='修改已完成；功能列與修改按鈕已鎖定，仍可檢視、複製或下載 Revision。';}catch(_){revisionBusy=false;updateRequirementPreview();if(requirementStatus)requirementStatus.textContent='無法標記修改完成。';}});
    historyRevision?.addEventListener('click',()=>{renderRevisionHistory();setHistoryFeedback(revisionState.selectedRevision?`目前選取 Revision ${revisionState.selectedRevision.revision_number}。`:'請按「選取此 Revision」明確選擇要操作的版本。','info');if(historyDialog&&!historyDialog.open)historyDialog.showModal();});
    root.querySelector('[data-image-revision-history-close]')?.addEventListener('click',()=>historyDialog?.close());
    root.querySelectorAll('[data-image-history-view]').forEach((button)=>button.addEventListener('click',(event)=>{event.preventDefault();historyArchived=button.dataset.imageHistoryView==='archived';renderRevisionHistory();}));

    save?.addEventListener('click',async()=>{if(workflowLocked())return;const missingNote=annotations.findIndex((item)=>ownedOpen(item)&&!item.note.trim());if(missingNote>=0){selectedIndex=missingNote;setTool('select');drawAll();renderList();dirty('請先填寫每一項標註的修改說明。');const textarea=list.querySelectorAll('textarea')[missingNote];if(textarea)textarea.focus({preventScroll:true});return;}const owned=annotations.filter(ownedOpen).map((item)=>({public_id:item.publicId,x:item.x,y:item.y,width:item.width,height:item.height,note:item.note,annotation_type:item.annotationType,geometry:item.geometry,style:item.style}));save.disabled=true;dirty('儲存中…');try{const response=await fetch(root.dataset.saveUrl||'',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrfToken||'',Accept:'application/json'},body:JSON.stringify({annotations:owned,source_hash:root.dataset.sourceHash||''})});const payload=await response.json();if(!response.ok||payload.ok!==true)throw new Error();location.reload();}catch(_){save.disabled=false;dirty('儲存失敗，請重新整理後再試。');}});
    setTool('browse'); renderList(); updateHistory(); updateRequirementPreview();renderRevisionHistory();
    if(requirementStatus) requirementStatus.textContent=revisionCollaboration.currentCompleted(revisionState)?'修改已完成；功能列與修改按鈕已鎖定，仍可檢視、複製或下載 Revision。':(revisionState.selectedRevision?`已選擇 Revision ${revisionState.selectedRevision.revision_number}；複製與下載固定使用此版本。`:'請先儲存修改內容，再整理至少一個 Revision。');
  }

  document.querySelectorAll('[data-image-review]').forEach((root)=>initReview(root).catch((error)=>{const status=root.querySelector('[data-image-review-status]');if(status)status.textContent=error?.message||'圖片標註功能無法載入。';}));
})();
