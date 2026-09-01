(function () {
  'use strict';

  var TOOLS = ['browse', 'select', 'rectangle', 'arrow', 'highlight', 'text', 'number'];
  var ANALYSIS_STATUSES = ['queued_analysis', 'analyzing'];
  var TOOL_LABELS = { browse: '瀏覽頁面', select: '移動／調整', rectangle: '框選', arrow: '箭頭', highlight: '螢光', text: '文字', number: '編號' };
  var STATUS_LABELS = {
    draft: '編輯中', queued_analysis: '規格整理已排程', analyzing: '正在整理精確規格', awaiting_approval: '規格已整理，可直接協作',
    approved: '舊流程已核准，可直接協作', completed: '修改已完成', analysis_failed: '規格整理失敗',
    rejected: '舊流程已退回，可繼續修改'
  };
  var SVG_NS = 'http://www.w3.org/2000/svg';

  function node(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(function (entry) {
      var key = entry[0], value = entry[1];
      if (key === 'className') el.className = value;
      else if (key === 'text') el.textContent = value;
      else if (key === 'dataset') Object.entries(value).forEach(function (pair) { el.dataset[pair[0]] = pair[1]; });
      else if (key === 'hidden') el.hidden = Boolean(value);
      else if (key === 'disabled') el.disabled = Boolean(value);
      else el.setAttribute(key, String(value));
    });
    (Array.isArray(children) ? children : children ? [children] : []).forEach(function (child) { if (child) el.appendChild(child); });
    return el;
  }

  function svgNode(tag, attrs) {
    var el = document.createElementNS(SVG_NS, tag);
    Object.entries(attrs || {}).forEach(function (entry) { el.setAttribute(entry[0], String(entry[1])); });
    return el;
  }

  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function bounded(value, max) { return String(value == null ? '' : value).trim().slice(0, max); }
  function cleanPromptText(value,max) { return String(value==null?'':value).replace(/\s+/g,' ').trim().slice(0,max||4000); }
  function fitInstructionTextarea(textarea){if(!textarea)return;textarea.style.height='auto';var contentHeight=Math.max(0,textarea.scrollHeight+2);textarea.style.height=Math.min(420,Math.max(96,contentHeight))+'px';textarea.style.overflowY=contentHeight>420?'auto':'hidden';}
  function batchTime(value) { var text=String(value||'').replace('T',' ');return text?text.slice(0,16):'—'; }
  function sortBatchTimeline(batches) { return batches.slice().sort(function(a,b){var time=String(b.updated_at||'').localeCompare(String(a.updated_at||''));return time||String(b.public_id||'').localeCompare(String(a.public_id||''));}); }
  function withFeedbackEmbed(value) {
    try { var url=new URL(String(value||'?action=documents'),window.location.href);if(url.origin!==window.location.origin)return String(value||'');url.searchParams.set('feedback_embed','1');return url.pathname+url.search+url.hash; }
    catch(_){return String(value||'');}
  }
  function exactPortalUrl(url) { return url.origin===window.location.origin&&url.pathname===window.location.pathname; }
  function uniqueAction(url) {
    var values=url.searchParams.getAll('action'),ambiguous=false;
    url.searchParams.forEach(function(_value,key){if(key.indexOf('action[')===0)ambiguous=true;});
    return !ambiguous&&values.length===1?String(values[0]):'';
  }
  function currentSearch(frame) {
    try {
      var url = new URL(frame.contentWindow.location.href);
      if (url.origin !== window.location.origin) throw new Error('cross origin');
      url.searchParams.delete('feedback_embed');
      return url.search || '?action=documents';
    } catch (_) { return frame.dataset.targetUrl || '?action=documents'; }
  }

  async function sha256(text) {
    var bytes = new TextEncoder().encode(String(text));
    var digest = await window.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }

  function stableSelector(element, doc) {
    if (!element || element === doc.documentElement || element === doc.body) return element ? element.tagName.toLowerCase() : '';
    if (element.id && /^[A-Za-z][A-Za-z0-9_:-]{0,120}$/.test(element.id)) return '#' + CSS.escape(element.id);
    var parts = [], cursor = element;
    while (cursor && cursor.nodeType === 1 && cursor !== doc.body && parts.length < 7) {
      var tag = cursor.tagName.toLowerCase();
      var parent = cursor.parentElement;
      if (!parent) break;
      var peers = Array.from(parent.children).filter(function (item) { return item.tagName === cursor.tagName; });
      if (peers.length > 1) tag += ':nth-of-type(' + (peers.indexOf(cursor) + 1) + ')';
      parts.unshift(tag);
      cursor = parent;
    }
    if (cursor === doc.body) parts.unshift('body');
    return parts.join(' > ').slice(0, 1000);
  }

  function FeedbackWorkspace(root, state, geometry) {
    this.root = root;
    this.state = state;
    this.geometryApi = geometry;
    this.batch = state.selected_batch || null;
    var requestedView=new URLSearchParams(window.location.search).get('batch_view');
    this.batchView=requestedView==='draft'||requestedView==='result'?requestedView:(this.batch&&['awaiting_approval','approved','completed'].includes(this.batch.status_code)?'result':'draft');
    this.items = this.batch ? clone(this.batch.items || []) : [];
    this.pendingDeletes = [];
    this.selectedId = null;
    this.selectionPulseTimer = null;
    this.tool = 'browse';
    this.history = [];
    this.future = [];
    this.drag = null;
    this.draftShape = null;
    this.saveBusy = false;
    this.savePromise = null;
    this.workflowBusy = false;
    this.editBusy = false;
    this.editEpoch = 0;
    this.pollTimer = null;
    this.pollInFlight = false;
    this.pollGeneration = 0;
    this.nodes = {};
  }

  FeedbackWorkspace.prototype.init = function () {
    this.buildShell();
    this.bindEvents();
    this.renderAll();
    var requestedAction=new URLSearchParams(window.location.search).get('target'),requestedTarget=(this.state.targets||[]).find(function(target){return target.action===requestedAction;});
    if(this.batch){var targetUrl=requestedTarget?.url||this.items[0]?.target_url;if(targetUrl)this.loadTarget(targetUrl);else if(this.state.targets?.length)this.openStartDialog();}
    else{this.showFreshStage();if(this.state.history_mode!=='archived'&&!this.batch&&this.state.targets?.length)this.openStartDialog();}
    if(this.state.history_mode==='archived'&&!this.nodes.batchDialog.open)this.nodes.batchDialog.showModal();
    this.pollIfNeeded();
  };

  FeedbackWorkspace.prototype.isAnalysisBusy = function () {
    return ANALYSIS_STATUSES.includes(this.batch?.status_code||'');
  };

  FeedbackWorkspace.prototype.stopPolling = function () {
    this.pollGeneration++;
    if(this.pollTimer!==null){clearTimeout(this.pollTimer);this.pollTimer=null;}
  };

  FeedbackWorkspace.prototype.buildShell = function () {
    var self = this;
    this.nodes.pageSelect = node('select', { 'aria-label': '選擇站內頁面' });
    (this.state.targets || []).forEach(function (target) { self.nodes.pageSelect.appendChild(node('option', { value: target.url, text: target.label })); });
    var pageGroup = node('div', { className: 'site-feedback-toolbar-group' }, [node('label', { className: 'site-feedback-page-picker', text: '實際頁面' }), this.nodes.pageSelect]);
    var toolGroup = node('div', { className: 'site-feedback-toolbar-group', role: 'toolbar', 'aria-label': '畫面標註工具' });
    TOOLS.forEach(function (tool) {
      var button = node('button', { type: 'button', className: 'site-feedback-tool-button', dataset: { tool: tool }, 'aria-pressed': tool === 'browse' ? 'true' : 'false', text: TOOL_LABELS[tool] });
      toolGroup.appendChild(button);
    });
    this.nodes.annotationToolGroup=toolGroup;
    var editGroup = node('div', { className: 'site-feedback-toolbar-group' }, [
      node('button', { type: 'button', className: 'site-feedback-tool-button', dataset: { action: 'undo' }, text: '復原' }),
      node('button', { type: 'button', className: 'site-feedback-tool-button', dataset: { action: 'redo' }, text: '重做' }),
      node('button', { type: 'button', className: 'site-feedback-tool-button is-danger', dataset: { action: 'delete-selection' }, text: '刪除' })
    ]);
    this.nodes.editToolGroup=editGroup;
    this.nodes.toolbar = node('div', { className: 'site-feedback-toolbar' }, [pageGroup, toolGroup, editGroup]);

    this.nodes.frame = node('iframe', { className: 'site-feedback-frame', title: '實際網站標註畫面', referrerpolicy: 'same-origin' });
    this.nodes.overlay = svgNode('svg', { class: 'site-feedback-overlay', role: 'img', 'aria-label': '網站畫面標註層' });
    var defs = svgNode('defs');
    var marker = svgNode('marker', { id: 'site-feedback-arrowhead', markerWidth: 12, markerHeight: 12, refX: 10, refY: 6, orient: 'auto', markerUnits: 'userSpaceOnUse' });
    marker.appendChild(svgNode('path', { d: 'M 0 0 L 12 6 L 0 12 z', fill: '#ef5b4c' })); defs.appendChild(marker); this.nodes.overlay.appendChild(defs);
    this.nodes.startPlaceholder=node('section',{className:'site-feedback-start-placeholder',hidden:true},[node('p',{className:'eyebrow',text:'START A NEW REQUEST'}),node('h2',{text:'請先選擇要修改的畫面'}),node('p',{text:'確認目標畫面後，系統才會在此載入全新的中央工作頁面。先前已儲存內容仍保留在「修改歷程」。'}),node('button',{type:'button',className:'primary-button',dataset:{action:'open-start'},text:'選擇修改畫面'})]);
    this.nodes.liveStage = node('div', { className: 'site-feedback-live-stage' }, [this.nodes.frame, this.nodes.overlay,this.nodes.startPlaceholder]);
    this.nodes.stageStatus = node('p', { className: 'site-feedback-stage-status', text: '切換到「瀏覽頁面」可操作實際頁面；切換標註工具後可直接框選。' });
    var center = node('section', { className: 'site-feedback-stage-column' }, [this.nodes.liveStage, this.nodes.stageStatus]);

    this.nodes.batchTitle = node('h2', { text: this.batch ? this.batch.title : '尚未建立需求批次' });
    this.nodes.batchStatus = node('span', { className: 'site-feedback-status', text: this.batch ? (STATUS_LABELS[this.batch.status_code] || this.batch.status_code) : '—' });
    this.nodes.annotationList = node('ol', { className: 'site-feedback-annotation-list' });
    this.nodes.batchPreview = node('section', { className: 'site-feedback-batch-preview' });
    this.nodes.summary = node('section', { className: 'site-feedback-analysis', hidden: true });
    this.nodes.message = node('p', { className: 'site-feedback-message', role: 'status' });
    this.nodes.analysisBusyTitle=node('strong',{text:'AI正在整理精確規格'});
    this.nodes.analysisBusyText=node('span',{text:'正在整理修改內容、位置與驗收條件。完成後會自動更新本頁。'});
    this.nodes.analysisBusyNotice=node('section',{className:'site-feedback-analysis-busy',role:'status','aria-live':'polite','aria-atomic':'true',hidden:true},[node('span',{className:'site-feedback-spinner','aria-hidden':'true'}),node('div',{},[this.nodes.analysisBusyTitle,this.nodes.analysisBusyText])]);
    this.nodes.reviewPanel = node('aside', { className: 'site-feedback-review-panel' }, [
      node('div', { className: 'site-feedback-panel-heading' }, [this.nodes.batchTitle, this.nodes.batchStatus]),
      this.nodes.analysisBusyNotice,
      node('p', { className: 'muted', text: '可直接在右側填寫每一項修改內容並儲存；完整標註與進階資料可在浮動視窗中檢視或修改。' }),
      this.nodes.batchPreview,this.nodes.summary,this.nodes.message
    ]);
    this.nodes.workspace = node('div', { className: 'site-feedback-workspace' }, [center, this.nodes.reviewPanel]);
    this.nodes.detailPrompt=node('section',{className:'site-feedback-detail-prompt'});
    this.nodes.detailDialog=node('dialog',{className:'site-feedback-detail-dialog','aria-labelledby':'site-feedback-detail-title'},[
      node('div',{className:'site-feedback-detail-dialog-heading'},[node('div',{},[node('p',{className:'eyebrow',text:'ANNOTATION DETAILS'}),node('h2',{id:'site-feedback-detail-title',text:'修改標註細節與 AI 提示'})]),node('button',{type:'button',className:'secondary-button',dataset:{action:'close-details'},'aria-label':'關閉修改標註細節',text:'關閉'})]),
      node('p',{className:this.batch?.archived_at?'site-feedback-archive-readonly-notice':'muted',text:this.batch?.archived_at?'封存資料僅供檢視；解除封存後才能編輯。':'在此檢視或編輯完整標註，並預覽／複製 AI 修改提示。'}),this.nodes.annotationList,this.nodes.detailPrompt
    ]);
    this.nodes.batchList = node('div',{className:'site-feedback-batch-list'});
    this.nodes.historySwitch=node('nav',{className:'site-feedback-history-view-switch','aria-label':'修改歷程顯示範圍'},[
      node('a',{className:this.state.history_mode==='archived'?'':'is-active',href:'?action=feedback',text:'目前歷程（'+(Number(this.state.archive_counts?.active)||0)+'）'}),
      node('a',{className:this.state.history_mode==='archived'?'is-active':'',href:'?action=feedback&history=archived',text:'封存資料（'+(Number(this.state.archive_counts?.archived)||0)+'）'})
    ]);
    this.nodes.batchDialog = node('dialog',{className:'site-feedback-batch-dialog','aria-labelledby':'site-feedback-batch-dialog-title'},[
      node('div',{className:'site-feedback-batch-dialog-heading'},[node('div',{},[node('p',{className:'eyebrow',text:'REQUIREMENT HISTORY'}),node('h2',{id:'site-feedback-batch-dialog-title',text:'修改歷程'})]),node('button',{type:'button',className:'secondary-button',dataset:{action:'close-batches'},'aria-label':'關閉修改歷程',text:'關閉'})]),
      this.nodes.historySwitch,node('p',{className:'muted',text:this.state.history_mode==='archived'?'封存資料預設不顯示；可在此檢視或解除封存。':'最近更新在上；封存後會從目前歷程隱藏，資料及截圖仍完整保留。'}),this.nodes.batchList
    ]);
    this.nodes.historySnapshotTitle=node('h2',{id:'site-feedback-history-snapshot-title',text:'歷程截圖'});
    this.nodes.historySnapshotBody=node('div',{className:'site-feedback-history-snapshot-gallery'});
    this.nodes.historySnapshotDialog=node('dialog',{className:'site-feedback-history-snapshot-dialog','aria-labelledby':'site-feedback-history-snapshot-title'},[
      node('div',{className:'site-feedback-history-snapshot-heading'},[node('div',{},[node('p',{className:'eyebrow',text:'IMMUTABLE SNAPSHOT'}),this.nodes.historySnapshotTitle]),node('button',{type:'button',className:'secondary-button',dataset:{action:'close-history-snapshot'},'aria-label':'關閉歷程截圖',text:'關閉'})]),
      this.nodes.historySnapshotBody
    ]);
    this.nodes.startTargetSelect=node('select',{'aria-label':'要修改的畫面'});(this.state.targets||[]).forEach(function(target){self.nodes.startTargetSelect.appendChild(node('option',{value:target.action,text:target.label}));});
    this.nodes.startTitleInput=node('input',{type:'text',maxlength:'200','aria-label':'新需求批次名稱'});this.nodes.startTitleField=node('label',{className:'site-feedback-start-field'},[node('span',{text:'這次修改的名稱'}),this.nodes.startTitleInput]);
    this.nodes.startDialog=node('dialog',{className:'site-feedback-start-dialog','aria-labelledby':'site-feedback-start-title'},[node('p',{className:'eyebrow',text:'CHOOSE TARGET PAGE'}),node('h2',{id:'site-feedback-start-title',text:'選擇要修改的畫面'}),node('p',{text:'請先確認這次要修改哪個頁面。確認後才會建立全新的需求批次並重新載入中央視窗，不會沿用上一批的標註。'}),node('label',{className:'site-feedback-start-field'},[node('span',{text:'目標畫面'}),this.nodes.startTargetSelect]),this.nodes.startTitleField,node('div',{className:'site-feedback-start-actions'},[node('button',{type:'button',className:'secondary-button',dataset:{action:'close-start'},text:'稍後再選'}),node('button',{type:'button',className:'primary-button',dataset:{action:'confirm-start'},text:'確認並載入畫面'})])]);
    this.root.replaceChildren(this.nodes.toolbar, this.nodes.workspace, this.nodes.batchDialog,this.nodes.historySnapshotDialog,this.nodes.detailDialog,this.nodes.startDialog);
  };

  FeedbackWorkspace.prototype.bindEvents = function () {
    var self = this;
    this.nodes.pageSelect.addEventListener('change', function () { if(self.workflowBusy||self.saveBusy||self.isAnalysisBusy())return;if(!self.batch){self.openStartDialog();return;}self.loadTarget(self.nodes.pageSelect.value); });
    this.nodes.startTargetSelect.addEventListener('change',function(){if(self.batch)return;var target=(self.state.targets||[]).find(function(entry){return entry.action===self.nodes.startTargetSelect.value;});if(target)self.nodes.startTitleInput.value=target.label+'畫面調整';});
    this.nodes.toolbar.addEventListener('click', function (event) {
      if(self.workflowBusy||self.saveBusy||self.isAnalysisBusy())return;
      var target = event.target.closest('button'); if (!target) return;
      if (target.dataset.tool) self.setTool(target.dataset.tool);
      if (target.dataset.action === 'undo') self.undo();
      if (target.dataset.action === 'redo') self.redo();
      if (target.dataset.action === 'delete-selection') self.deleteSelection();
    });
    this.nodes.overlay.addEventListener('pointerdown', function (event) { self.pointerdown(event); });
    this.nodes.overlay.addEventListener('pointermove', function (event) { self.pointermove(event); });
    this.nodes.overlay.addEventListener('pointerup', function (event) { self.pointerup(event); });
    this.nodes.overlay.addEventListener('pointercancel', function () { self.cancelDrag(); self.renderOverlay(); });
    this.nodes.frame.addEventListener('load', function () { self.onFrameLoad(); });
    var handleAction=function(event){
      var action = event.target.closest('[data-action]')?.dataset.action;
      if((self.saveBusy||self.snapshotBusy||self.workflowBusy)&&['save','submit','complete','capture-snapshot'].includes(action||''))return;
      if(self.isAnalysisBusy()&&['save','submit','complete','capture-snapshot','open-start','archive-batch','restore-batch'].includes(action||''))return;
      if (action === 'save') self.saveAll();
      if (action === 'submit') self.submitBatch();
      if (action === 'complete') self.completeBatch();
      if (action === 'capture-snapshot') { var snapshotButton=event.target.closest('[data-snapshot-kind]'); if(snapshotButton)self.captureCurrentSnapshot(snapshotButton.dataset.snapshotKind); }
      if (action === 'close-batches') self.nodes.batchDialog.close();
      if (action === 'open-history-snapshot') {var historySnapshotButton=event.target.closest('[data-batch-id][data-snapshot-kind]');if(historySnapshotButton)self.openHistorySnapshot(historySnapshotButton.dataset.batchId,historySnapshotButton.dataset.snapshotKind);}
      if (action === 'close-history-snapshot') self.nodes.historySnapshotDialog.close();
      if (action === 'open-details'&&!self.nodes.detailDialog.open){self.renderList();self.renderDetailPrompt();self.nodes.detailDialog.showModal();requestAnimationFrame(function(){self.nodes.detailDialog.querySelectorAll('.site-feedback-annotation-item textarea').forEach(fitInstructionTextarea);});}
      if (action === 'close-details') {self.renderBatchPreview();self.nodes.detailDialog.close();}
      if (action === 'open-start') self.openStartDialog();
      if (action === 'close-start'&&self.nodes.startDialog.open) self.nodes.startDialog.close();
      if (action === 'confirm-start') self.startNewTarget();
      if (action === 'download-json') {if(!self.aiPromptReady())self.message('請先儲存全部修改內容，再下載 AI 修改需求 JSON。',true);else self.downloadExternalPromptJson();}
      if (action === 'archive-batch') {var archiveButton=event.target.closest('[data-batch-id]');if(archiveButton)self.archiveBatch(archiveButton.dataset.batchId,false);}
      if (action === 'restore-batch') {var restoreButton=event.target.closest('[data-batch-id]');if(restoreButton)self.archiveBatch(restoreButton.dataset.batchId,true);}
      var copyButton=event.target.closest('[data-copy-kind]');if(copyButton){if(copyButton.dataset.copyKind==='external'&&!self.aiPromptReady()){self.message('請先儲存全部修改內容，系統才會彙整 AI 修改提示詞。',true);return;}var copyValue=copyButton.dataset.copyKind==='discord'?self.buildDiscordConfirmation():(copyButton.dataset.copyKind==='batch-id'?copyButton.dataset.copyValue:self.buildExternalPrompt());self.copyText(copyValue);}
      var selectButton = event.target.closest('.site-feedback-item-select'); if (selectButton) { var select = selectButton.closest('[data-item-id]'); self.selectedId = select ? select.dataset.itemId : null; self.renderAll(); }
    };
    this.nodes.workspace.addEventListener('click',handleAction);this.nodes.batchDialog.addEventListener('click',handleAction);this.nodes.historySnapshotDialog.addEventListener('click',handleAction);this.nodes.detailDialog.addEventListener('click',handleAction);this.nodes.startDialog.addEventListener('click',handleAction);
    this.nodes.batchDialog.addEventListener('click',function(event){if(event.target===self.nodes.batchDialog)self.nodes.batchDialog.close();});
    this.nodes.historySnapshotDialog.addEventListener('click',function(event){if(event.target===self.nodes.historySnapshotDialog)self.nodes.historySnapshotDialog.close();});
    this.nodes.detailDialog.addEventListener('click',function(event){if(event.target===self.nodes.detailDialog)self.nodes.detailDialog.close();});
    this.nodes.detailDialog.addEventListener('close',function(){self.renderBatchPreview();});
    this.nodes.createTrigger=document.querySelector('[data-feedback-create]');
    this.nodes.historyTrigger=document.querySelector('[data-feedback-batches]');
    this.nodes.createTrigger?.addEventListener('click', function () { if(self.isAnalysisBusy())return;self.createBatch(); });
    this.nodes.historyTrigger?.addEventListener('click', function () { if(self.isAnalysisBusy())return;if(!self.nodes.batchDialog.open)self.nodes.batchDialog.showModal(); });
  };

  FeedbackWorkspace.prototype.showFreshStage = function () {
    this.nodes.frame.hidden=true;this.nodes.overlay.hidden=true;this.nodes.startPlaceholder.hidden=false;this.nodes.stageStatus.textContent='尚未載入畫面。請先選擇這次要修改的目標頁面。';
  };
  FeedbackWorkspace.prototype.openStartDialog = function () {
    if(this.isAnalysisBusy())return;var target=(this.state.targets||[]).find(function(entry){return entry.action===this.nodes.startTargetSelect.value;},this)||(this.state.targets||[])[0];if(!target)return;
    this.nodes.startTargetSelect.value=target.action;this.nodes.startTitleField.hidden=Boolean(this.batch);if(!this.batch)this.nodes.startTitleInput.value=target.label+'畫面調整';if(!this.nodes.startDialog.open)this.nodes.startDialog.showModal();
  };
  FeedbackWorkspace.prototype.startNewTarget = async function () {
    if(this.workflowBusy||this.isAnalysisBusy())return;var target=(this.state.targets||[]).find(function(entry){return entry.action===this.nodes.startTargetSelect.value;},this);if(!target){this.message('請選擇要修改的畫面。',true);return;}
    if(this.batch){this.nodes.startDialog.close();this.loadTarget(target.url);return;}
    var title=bounded(this.nodes.startTitleInput.value,200)||target.label+'畫面調整';this.workflowBusy=true;var button=this.nodes.startDialog.querySelector('[data-action="confirm-start"]');if(button)button.disabled=true;
    try{var result=await this.api(this.state.urls.create,{title:title});window.location.href='?action=feedback&id='+encodeURIComponent(result.batch.public_id)+'&target='+encodeURIComponent(target.action);}
    catch(error){this.workflowBusy=false;if(button)button.disabled=false;this.nodes.startDialog.querySelector('[data-start-error]')?.remove();this.nodes.startDialog.appendChild(node('p',{className:'site-feedback-message is-error',role:'alert',dataset:{startError:'1'},text:error.message||'無法建立新的需求批次。'}));}
  };

  FeedbackWorkspace.prototype.setWorkflowBusy = function (busy) {
    this.workflowBusy=Boolean(busy);if(this.nodes.frame){this.nodes.frame.inert=this.workflowBusy;this.nodes.frame.style.pointerEvents=this.workflowBusy?'none':'';}if(this.nodes.overlay)this.nodes.overlay.style.pointerEvents=this.workflowBusy?'none':'';this.renderAll();
  };

  FeedbackWorkspace.prototype.snapshot = function () {
    this.history.push({ items: clone(this.items), pendingDeletes: clone(this.pendingDeletes), selectedId: this.selectedId });
    if (this.history.length > 50) this.history.shift(); this.future = [];
  };
  FeedbackWorkspace.prototype.undo = function () { if(!this.isEditable()||this.saveBusy||this.workflowBusy)return;var previous = this.history.pop(); if (!previous) return; this.future.push({ items: clone(this.items), pendingDeletes: clone(this.pendingDeletes), selectedId: this.selectedId }); Object.assign(this, previous);this.editEpoch++; this.renderAll(); };
  FeedbackWorkspace.prototype.redo = function () { if(!this.isEditable()||this.saveBusy||this.workflowBusy)return;var next = this.future.pop(); if (!next) return; this.history.push({ items: clone(this.items), pendingDeletes: clone(this.pendingDeletes), selectedId: this.selectedId }); Object.assign(this, next);this.editEpoch++; this.renderAll(); };

  FeedbackWorkspace.prototype.setTool = function (tool) {
    if(this.isAnalysisBusy())return;
    if (TOOLS.indexOf(tool) === -1) return;
    if(!this.isEditable()&&tool!=='browse')return;
    this.tool = tool;
    this.nodes.overlay.classList.toggle('is-browsing', tool === 'browse');
    this.nodes.overlay.classList.toggle('is-drawing', !['browse', 'select'].includes(tool));
    this.nodes.overlay.classList.toggle('is-selecting', tool === 'select');
    this.nodes.toolbar.querySelectorAll('[data-tool]').forEach(function (button) { button.setAttribute('aria-pressed', button.dataset.tool === tool ? 'true' : 'false'); });
    this.nodes.stageStatus.textContent = tool === 'browse' ? '現在可操作與捲動實際頁面。選擇標註工具後即可圈選位置。' : (tool==='select'?'點選任一框選、箭頭、螢光、文字或編號並直接拖曳，即可修正位置。':'正在使用「' + TOOL_LABELS[tool] + '」；位置會同時記錄頁面、viewport、捲動量與 DOM 元素。');
  };

  FeedbackWorkspace.prototype.loadTarget = function (url) {
    url = String(url || '?action=documents');
    if(this.isAnalysisBusy()&&((this.nodes.frame.dataset.targetUrl&&this.nodes.frame.dataset.targetUrl!==url)||(!this.nodes.frame.dataset.targetUrl&&!this.items.some(function(item){return item.target_url===url;}))))return;
    this.nodes.startPlaceholder.hidden=true;this.nodes.frame.hidden=false;this.nodes.overlay.hidden=false;
    this.nodes.frame.dataset.targetUrl = url;
    this.nodes.frame.src = withFeedbackEmbed(url);
    this.nodes.pageSelect.value = url;
  };

  FeedbackWorkspace.prototype.decorateFrameNavigation = function () {
    var doc=this.nodes.frame.contentDocument,allowed=new Set((this.state.embed_actions||[]).map(String));
    doc.querySelectorAll('a[href]').forEach(function(anchor){try{var url=new URL(anchor.href,doc.URL);if(url.origin!==window.location.origin)return;var keep=exactPortalUrl(url)&&allowed.has(uniqueAction(url));url.searchParams.delete('feedback_embed');if(keep){url.searchParams.set('feedback_embed','1');anchor.removeAttribute('target');}else anchor.target='_top';anchor.href=url.pathname+url.search+url.hash;}catch(_){}});
    doc.querySelectorAll('form').forEach(function(form){try{var url=new URL(form.getAttribute('action')||doc.URL,doc.URL);if(url.origin!==window.location.origin)return;var method=String(form.method||'get').toLowerCase(),values=[];Array.from(form.elements||[]).forEach(function(control){if(control.name==='action'&&!control.disabled&&String(control.value||'')!=='')values.push(String(control.value));});var markers=form.querySelectorAll('input[name="feedback_embed"]'),marker=markers[0]||null,hasOverride=Boolean(form.querySelector('[formmethod],[formaction],[formtarget]')),keep=method==='get'&&!hasOverride&&exactPortalUrl(url)&&values.length===1&&allowed.has(values[0]);url.searchParams.delete('feedback_embed');form.action=url.pathname+url.search+url.hash;if(keep){Array.from(markers).slice(1).forEach(function(extra){extra.remove();});if(!marker){marker=doc.createElement('input');marker.type='hidden';marker.name='feedback_embed';form.appendChild(marker);}marker.value='1';form.removeAttribute('target');}else{markers.forEach(function(item){item.remove();});form.querySelectorAll('[formtarget]').forEach(function(control){control.setAttribute('formtarget','_top');});form.target='_top';}}catch(_){form.target='_top';}});
  };

  FeedbackWorkspace.prototype.onFrameLoad = function () {
    if(!this.nodes.frame.dataset.targetUrl)return;
    try {
      var url = new URL(this.nodes.frame.contentWindow.location.href);
      if (url.origin !== window.location.origin) throw new Error('cross origin');
      this.nodes.frame.dataset.targetUrl = currentSearch(this.nodes.frame);
      this.decorateFrameNavigation();
      var self=this,frameWindow=this.nodes.frame.contentWindow,target=currentSearch(this.nodes.frame);
      frameWindow.addEventListener('scroll',function(){self.renderOverlay();},{passive:true});
      frameWindow.addEventListener('resize',function(){self.renderOverlay();},{passive:true});
      var restore=this.items.find(function(item){return item.target_url===target;});
      var restoreKey=(this.batch?.public_id||'')+':'+target;
      if(restore&&this.restoredTargetKey!==restoreKey){this.restoredTargetKey=restoreKey;frameWindow.scrollTo(Math.max(0,Number(restore.scroll_x)||0),Math.max(0,Number(restore.scroll_y)||0));}
      this.nodes.stageStatus.textContent = '實際頁面已載入。可先瀏覽到目標狀態，再切換框選、箭頭、螢光、文字或編號。';
    } catch (_) { this.nodes.stageStatus.textContent = '此頁面不符合站內標註安全範圍。'; }
    this.renderOverlay();this.applyBatchViewMode();
  };

  FeedbackWorkspace.prototype.eventPoint = function (event) {
    var rect = this.nodes.overlay.getBoundingClientRect();
    return this.geometryApi.clampPoint({ x: event.clientX - rect.left, y: event.clientY - rect.top }, rect.width, rect.height);
  };

  FeedbackWorkspace.prototype.isEditable = function () { return !this.batch?.archived_at&&this.batchView==='draft'&&['draft','analysis_failed','rejected'].includes(this.batch?.status_code||''); };
  FeedbackWorkspace.prototype.hitItemAt = function (point) {
    for(var i=this.items.length-1;i>=0;i--){var item=this.items[i];if(item.target_url!==currentSearch(this.nodes.frame))continue;var p=this.itemPixels(item),hit;
      if(item.annotation_type_code==='arrow')hit=this.geometryApi.distanceToSegment(point,{x:p.x1,y:p.y1},{x:p.x2,y:p.y2})<24;
      else if(item.annotation_type_code==='number'){var cx=p.left+p.width/2,cy=p.top+p.height/2;hit=Math.hypot(point.x-cx,point.y-cy)<=Math.max(p.width,p.height)/2+8;}
      else hit=point.x>=p.left&&point.x<=p.left+p.width&&point.y>=p.top&&point.y<=p.top+p.height;
      if(hit)return item;
    }return null;
  };
  FeedbackWorkspace.prototype.highlightCanvasSelection = function () {
    var id=this.selectedId===null?'':String(this.selectedId);this.nodes.overlay.querySelectorAll('[data-item-id]').forEach(function(shape){shape.classList.toggle('is-selected',id!==''&&String(shape.dataset.itemId)===id);});
  };
  FeedbackWorkspace.prototype.syncCardSelection = function () {
    var id=this.selectedId===null?'':String(this.selectedId);[this.nodes.batchPreview,this.nodes.annotationList].forEach(function(container){if(!container)return;container.querySelectorAll('[data-item-id]').forEach(function(card){var selected=id!==''&&String(card.dataset.itemId)===id;card.classList.toggle('is-selected',selected);if(selected)card.setAttribute('aria-current','true');else card.removeAttribute('aria-current');card.querySelectorAll('.site-feedback-selection-chip').forEach(function(chip){chip.remove();});if(selected){var heading=card.querySelector('.site-feedback-annotation-heading strong')||Array.from(card.children).find(function(child){return child.tagName==='STRONG';});if(heading)heading.appendChild(node('span',{className:'site-feedback-selection-chip',text:'目前選取'}));}});});
  };
  FeedbackWorkspace.prototype.pointerdown = function (event) {
    if(this.saveBusy||this.workflowBusy||this.editBusy||this.drag){this.message('上一個標註或系統處理尚未完成，請稍候。');return;}
    if (this.tool === 'browse') return;
    var point=this.eventPoint(event);
    if (this.tool === 'select') {
      event.preventDefault();var selected=this.hitItemAt(point);this.selectedId=selected?String(selected.public_id):null;
      if(selected&&this.isEditable()){this.nodes.overlay.setPointerCapture(event.pointerId);this.drag={ mode: 'move', pointerId:event.pointerId,start:point,current:point,itemId:String(selected.public_id),originalPixels:this.itemPixels(selected),previewPixels:this.itemPixels(selected),moved:false };}
      this.highlightCanvasSelection();this.syncCardSelection();this.revealSelectedAnnotation(false);return;
    }
    if(!this.isEditable()){this.message('此批次目前不可編修；只有編輯中、規格整理失敗或舊流程退回的批次可以調整。',true);return;}
    event.preventDefault(); this.nodes.overlay.setPointerCapture(event.pointerId);
    this.drag = { mode: 'draw', pointerId: event.pointerId, start: point, current: point };
    this.drawDraft();
  };
  FeedbackWorkspace.prototype.updateMovePreview = function (event) {
    if(!this.drag||this.drag.mode!=='move'||event.pointerId!==this.drag.pointerId)return false;
    this.drag.current=this.eventPoint(event);var rect=this.nodes.overlay.getBoundingClientRect(),delta={x:this.drag.current.x-this.drag.start.x,y:this.drag.current.y-this.drag.start.y};this.drag.moved=Math.hypot(delta.x,delta.y)>=2;this.drag.previewPixels=this.geometryApi.translateViewportPixels(this.items.find(function(item){return String(item.public_id)===this.drag.itemId;},this)?.annotation_type_code||'rectangle',this.drag.originalPixels,delta,rect.width,rect.height);return true;
  };
  FeedbackWorkspace.prototype.pointermove = function (event) {
    if (!this.drag || event.pointerId !== this.drag.pointerId) return;event.preventDefault();
    if(this.drag.mode==='move'){this.updateMovePreview(event);this.renderOverlay();return;}
    this.drag.current=this.eventPoint(event);
    this.drawDraft();
  };
  FeedbackWorkspace.prototype.pointerup = async function (event) {
    if (!this.drag || event.pointerId !== this.drag.pointerId) return;
    event.preventDefault();
    if(this.drag.mode==='move'){this.updateMovePreview(event);await this.commitMove();return;}
    this.drag.current = this.eventPoint(event);
    var rect = this.nodes.overlay.getBoundingClientRect(), geometry;
    try {
      geometry = this.tool === 'arrow' ? this.geometryApi.normalizeArrow(this.drag.start, this.drag.current, rect.width, rect.height) : (this.tool==='number'?this.geometryApi.markerBoxAt(this.drag.current,rect.width,rect.height,40):this.geometryApi.normalizeBox(this.drag.start, this.drag.current, rect.width, rect.height));
    } catch (_) { this.cancelDrag(); this.message('標註範圍太小，請重新圈選。', true); return; }
    this.editBusy=true;
    try{
      var anchor=await this.captureAnchor(this.drag.current);if(this.workflowBusy||this.saveBusy)throw new Error('workflow locked');this.snapshot();
      var clientId = 'draft-' + (window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now().toString(36));
      var item = Object.assign({public_id:clientId,annotation_type_code:this.tool,geometry:geometry,target_url:currentSearch(this.nodes.frame),instruction_text:'',sequence_number:this.items.length+1,_dirty:true,_new:true,_edit_version:1},anchor);
      this.items.push(item);this.editEpoch++;this.selectedId=clientId;this.cancelDrag();this.renderAll();this.setTool('select');this.revealSelectedAnnotation(true);this.message('標註已建立並定位到右側說明；已切換到「移動／調整」，可直接拖曳修正位置。');
      var textarea=this.nodes.batchPreview.querySelector('[data-item-id="'+CSS.escape(clientId)+'"] textarea');if(textarea)textarea.focus({preventScroll:true});
    }catch(_){this.cancelDrag();this.message('無法取得此位置的站內頁面資訊，請回到允許的頁面後重試。',true);}finally{this.editBusy=false;}
  };
  FeedbackWorkspace.prototype.commitMove = async function () {
    var drag=this.drag,item=this.items.find(function(entry){return String(entry.public_id)===drag.itemId;});if(!item){this.cancelDrag();return;}
    if(!drag.moved){this.cancelDrag();this.renderAll();this.revealSelectedAnnotation(true);return;}if(this.saveBusy||this.workflowBusy||this.editBusy){this.cancelDrag();this.renderAll();return;}
    this.editBusy=true;var rect=this.nodes.overlay.getBoundingClientRect(),p=drag.previewPixels,geometry,anchorPoint;
    try{if(item.annotation_type_code==='arrow'){geometry=this.geometryApi.normalizeArrow({x:p.x1,y:p.y1},{x:p.x2,y:p.y2},rect.width,rect.height);anchorPoint={x:p.x2,y:p.y2};}else{geometry=this.geometryApi.normalizeBox({x:p.left,y:p.top},{x:p.left+p.width,y:p.top+p.height},rect.width,rect.height);anchorPoint={x:p.left+p.width/2,y:p.top+p.height/2};}
      var anchor=await this.captureAnchor(anchorPoint);if(this.workflowBusy||this.saveBusy)throw new Error('workflow locked');this.history.push({items:clone(this.items),pendingDeletes:clone(this.pendingDeletes),selectedId:this.selectedId});if(this.history.length>50)this.history.shift();this.future=[];item.geometry=geometry;item.target_url=currentSearch(this.nodes.frame);Object.assign(item,anchor);item._edit_version=(Number(item._edit_version)||0)+1;item._dirty=true;this.editEpoch++;this.cancelDrag();this.renderAll();this.revealSelectedAnnotation(true);this.message('標註已移動並定位到右側說明；請記得儲存。');
    }catch(_){this.cancelDrag();this.renderAll();this.message('無法移動到此位置，標註維持原位。',true);}finally{this.editBusy=false;}
  };
  FeedbackWorkspace.prototype.cancelDrag = function () { this.drag = null; if (this.draftShape) this.draftShape.remove(); this.draftShape = null; };

  FeedbackWorkspace.prototype.drawDraft = function () {
    if (!this.drag) return; if (this.draftShape) this.draftShape.remove();
    var a = this.drag.start, b = this.drag.current;
    if (this.tool === 'arrow') this.draftShape = svgNode('line', { x1:a.x,y1:a.y,x2:b.x,y2:b.y,class:'site-feedback-shape is-draft is-arrow','marker-end':'url(#site-feedback-arrowhead)' });
    else if(this.tool==='number'){var marker=this.geometryApi.geometryToPixels('number',this.geometryApi.markerBoxAt(b,this.nodes.overlay.getBoundingClientRect().width,this.nodes.overlay.getBoundingClientRect().height,40),this.nodes.overlay.getBoundingClientRect().width,this.nodes.overlay.getBoundingClientRect().height);this.draftShape=svgNode('circle',{cx:marker.left+marker.width/2,cy:marker.top+marker.height/2,r:Math.min(marker.width,marker.height)/2,class:'site-feedback-shape is-draft is-number'});}
    else this.draftShape = svgNode('rect', { x:Math.min(a.x,b.x),y:Math.min(a.y,b.y),width:Math.abs(b.x-a.x),height:Math.abs(b.y-a.y),class:'site-feedback-shape is-draft is-'+this.tool });
    this.nodes.overlay.appendChild(this.draftShape);
  };

  FeedbackWorkspace.prototype.captureAnchor = async function (point) {
    var frameWindow = this.nodes.frame.contentWindow, doc = this.nodes.frame.contentDocument;
    var width = Math.max(1, frameWindow.innerWidth), height = Math.max(1, frameWindow.innerHeight);
    var el = doc.elementFromPoint(point.x, point.y); // elementFromPoint intentionally binds the visible marker to a real DOM target.
    var selector = stableSelector(el, doc), text = bounded(el?.innerText || el?.textContent || '', 1000), tag = el?.tagName?.toLowerCase() || '';
    var pageMaterial = [currentSearch(this.nodes.frame), doc.title, bounded(doc.body?.innerText || '', 20000), width, height].join('\n');
    var elementMaterial = [selector, tag, text, el ? JSON.stringify(el.getBoundingClientRect().toJSON ? el.getBoundingClientRect().toJSON() : {}) : ''].join('\n');
    return {
      viewport_width: width, viewport_height: height, scroll_x: Math.max(0, Math.round(frameWindow.scrollX)), scroll_y: Math.max(0, Math.round(frameWindow.scrollY)),
      device_pixel_ratio: Math.min(8, Math.max(.25, Number(frameWindow.devicePixelRatio || window.devicePixelRatio || 1))),
      page_fingerprint: await sha256(pageMaterial), element_selector: selector, element_tag: tag, element_text: text,
      element_fingerprint: await sha256(elementMaterial)
    };
  };

  FeedbackWorkspace.prototype.syncBatchSummary = function () {
    if(!this.batch)return;var listed=(this.state.batches||[]).find(function(entry){return String(entry.public_id)===String(this.batch.public_id);},this);if(!listed)return;
    listed.title=this.batch.title;listed.status_code=this.batch.status_code;listed.revision_number=this.batch.revision_number;listed.updated_at=this.batch.updated_at;listed.completed_at=this.batch.completed_at;listed.item_count=this.items.length;listed.snapshots=clone(this.batch.snapshots||[]);this.state.batches=sortBatchTimeline(this.state.batches||[]);
  };
  FeedbackWorkspace.prototype.renderAll = function () {
    this.syncBatchSummary();this.renderBatchDialog();this.renderBatchPreview();this.renderList();this.renderDetailPrompt();this.renderOverlay();this.renderStatus();this.applyBatchViewMode();
  };
  FeedbackWorkspace.prototype.revealSelectedAnnotation = function (scroll) {
    if(!this.selectedId)return;var self=this,id=String(this.selectedId),find=function(container){return container?.querySelector('[data-item-id="'+CSS.escape(id)+'"]')||null;},main=find(this.nodes.batchPreview),detail=find(this.nodes.annotationList),targets=[main,detail].filter(Boolean);
    if(targets.length===0)return;targets.forEach(function(card){card.classList.add('is-locator-flash');card.setAttribute('aria-current','true');});
    if(this.selectionPulseTimer)window.clearTimeout(this.selectionPulseTimer);this.selectionPulseTimer=window.setTimeout(function(){targets.forEach(function(card){card.classList.remove('is-locator-flash');});self.selectionPulseTimer=null;},1500);
    if(scroll){var target=this.nodes.detailDialog.open&&detail?detail:(main||detail),behavior=window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',position=this.items.findIndex(function(item){return String(item.public_id)===id;})+1;requestAnimationFrame(function(){target.scrollIntoView({behavior:behavior,block:'center',inline:'nearest'});});if(position>0)this.message('已定位到右側第 '+position+' 項修改說明。');}
  };
  FeedbackWorkspace.prototype.applyBatchViewMode = function(){
    var resultView=this.batchView==='result';
    this.nodes.overlay.toggleAttribute('hidden',resultView);this.nodes.overlay.setAttribute('aria-hidden',resultView?'true':'false');
    this.nodes.annotationList.hidden=resultView;this.nodes.annotationToolGroup.hidden=resultView;this.nodes.editToolGroup.hidden=resultView;
    this.nodes.workspace.classList.toggle('is-result-view',resultView);
    this.nodes.stageStatus.textContent=resultView?'修改結果：已隱藏指引框與標註內容，中央只顯示目前網站。':'修改內容：中央是目前即時網站，不是修改前歷史截圖；框線與文字是保存的修改指引。';
  };
  FeedbackWorkspace.prototype.renderBatchDialog = function () {
    var self=this;this.nodes.batchList.replaceChildren();
    if(!(this.state.batches||[]).length){this.nodes.batchList.appendChild(node('p',{className:'site-feedback-empty',text:'目前沒有需求批次。'}));return;}
    (this.state.batches||[]).forEach(function(batch,index){
      var selected=String(self.batch?.public_id||'')===String(batch.public_id),archived=Boolean(batch.archived_at),originalStatus=STATUS_LABELS[batch.status_code]||batch.status_code,status=archived?'已封存':originalStatus;
      var summary=node('summary',{className:'site-feedback-batch-list-summary'},[
        node('span',{className:'site-feedback-batch-sequence',text:String(index+1).padStart(2,'0')}),
        node('strong',{text:batch.title}),node('time',{datetime:String(batch.updated_at||''),text:batchTime(batch.updated_at)}),node('span',{className:'site-feedback-status',text:status})
      ]);
      var identity=node('div',{className:'site-feedback-batch-identity'},[node('span',{text:'批次 ID'}),node('code',{text:batch.public_id})]);
      var meta=node('p',{className:'site-feedback-batch-meta',text:'Revision '+batch.revision_number+'・'+batch.item_count+' 項需求'+(archived?'・原狀態：'+originalStatus:'')});
      var actions=node('div',{className:'site-feedback-batch-row-actions'},[
        node('button',{type:'button',className:'secondary-button','aria-label':'檢視「'+batch.title+'」的修改前畫面截圖',dataset:{action:'open-history-snapshot',batchId:batch.public_id,snapshotKind:'before'},text:'修改前畫面截圖'}),
        node('button',{type:'button',className:'secondary-button','aria-label':'檢視「'+batch.title+'」的修改後結果截圖',dataset:{action:'open-history-snapshot',batchId:batch.public_id,snapshotKind:'after'},text:'修改後結果截圖'}),
        node('button',{type:'button',className:'secondary-button','aria-label':'複製「'+batch.title+'」的批次 ID',dataset:{copyKind:'batch-id',copyValue:batch.public_id},text:'複製 ID'})
      ]);
      if(archived)actions.appendChild(node('button',{type:'button',className:'secondary-button site-feedback-archive-button','aria-label':'解除封存「'+batch.title+'」',dataset:{action:'restore-batch',batchId:batch.public_id},text:'解除封存'}));
      else if(['draft','awaiting_approval','approved','completed','analysis_failed','rejected'].includes(batch.status_code))actions.appendChild(node('button',{type:'button',className:'secondary-button site-feedback-archive-button','aria-label':'封存「'+batch.title+'」',dataset:{action:'archive-batch',batchId:batch.public_id},text:'封存'}));
      self.nodes.batchList.appendChild(node('details',{className:'site-feedback-batch-list-row'+(selected?' is-selected':'')+(archived?' is-archived':'')},[summary,node('div',{className:'site-feedback-batch-list-detail'},[identity,meta,actions])]));
    });
  };
  FeedbackWorkspace.prototype.openHistorySnapshot = function(batchId,kind){
    if(!['before','after'].includes(kind))return;
    var batch=this.batch&&String(this.batch.public_id)===String(batchId)?this.batch:(this.state.batches||[]).find(function(entry){return String(entry.public_id)===String(batchId);});if(!batch)return;
    var before=kind==='before',revision=Number(batch.revision_number)||0,snapshots=(batch.snapshots||[]).filter(function(snapshot){return snapshot.snapshot_kind===kind&&Number(snapshot.revision_number)===revision;}),self=this;
    this.nodes.historySnapshotTitle.textContent=(before?'修改前畫面截圖':'修改後結果截圖')+'｜'+batch.title;
    this.nodes.historySnapshotBody.replaceChildren();
    if(snapshots.length===0){this.nodes.historySnapshotBody.appendChild(node('section',{className:'site-feedback-history-snapshot-empty'},[node('strong',{text:'沒有可顯示的歷程截圖'}),node('p',{text:'此批次的這個 Revision 沒有保存'+(before?'修改前畫面截圖':'修改後結果截圖')+'；無法用目前網站重建歷史畫面。'})]));}
    else snapshots.forEach(function(snapshot,index){var target=(self.state.targets||[]).find(function(entry){return entry.url===snapshot.target_url;}),label=target?.label||('目標頁面 '+(index+1)),image=node('img',{className:'site-feedback-history-snapshot-image',src:snapshot.image_url,alt:(before?'修改前畫面截圖':'修改後結果截圖')+'：'+label+'，Revision '+snapshot.revision_number,loading:'lazy'});self.nodes.historySnapshotBody.appendChild(node('figure',{className:'site-feedback-history-snapshot-figure'},[node('h3',{text:label}),image,node('figcaption',{text:'Revision '+snapshot.revision_number+'・'+snapshot.image_width+'×'+snapshot.image_height+'・不可覆寫歷程快照'})]));});
    if(!this.nodes.historySnapshotDialog.open)this.nodes.historySnapshotDialog.showModal();
  };
  FeedbackWorkspace.prototype.describeRelativePosition = function(item){
    var geometry=item?.geometry||{},pct=function(value){var number=Number(value);return Number.isFinite(number)?(Math.round(number*1000)/10)+'%':'未知';},horizontal=function(value){return value<.34?'左側':(value<.67?'中央':'右側');},vertical=function(value){return value<.34?'上方':(value<.67?'中段':'下方');};
    if(item?.annotation_type_code==='arrow'){var cx=(Number(geometry.x1)+Number(geometry.x2))/2,cy=(Number(geometry.y1)+Number(geometry.y2))/2;return '箭頭位於可視畫面'+vertical(cy)+horizontal(cx)+'；起點距左側 '+pct(geometry.x1)+'、距上方 '+pct(geometry.y1)+'，終點距左側 '+pct(geometry.x2)+'、距上方 '+pct(geometry.y2)+'。';}
    var x=Number(geometry.x),y=Number(geometry.y),width=Number(geometry.width),height=Number(geometry.height),centerX=x+width/2,centerY=y+height/2;
    return '框選相對位置：位於可視畫面'+vertical(centerY)+horizontal(centerX)+'；左緣距畫面左側 '+pct(x)+'，上緣距畫面上方 '+pct(y)+'，寬度約佔 '+pct(width)+'，高度約佔 '+pct(height)+'，中心點約在（'+pct(centerX)+', '+pct(centerY)+'）。';
  };
  FeedbackWorkspace.prototype.aiPromptReady = function(){
    return Boolean(this.batch)&&['awaiting_approval','approved','completed'].includes(this.batch?.status_code||'')&&this.items.length>0&&this.pendingDeletes.length===0&&this.items.every(function(item){return !item._new&&!item._dirty&&Boolean(String(item.instruction_text||'').trim())&&Boolean(String(item.structured_requirement||'').trim());});
  };
  FeedbackWorkspace.prototype.invalidateAiPrompt = function(){
    [this.nodes.batchPreview,this.nodes.detailPrompt].forEach(function(container){container?.querySelectorAll('[data-copy-kind="external"],[data-action="download-json"]').forEach(function(control){control.disabled=true;});});if(this.nodes.detailPrompt)this.renderDetailPrompt();
  };
  FeedbackWorkspace.prototype.buildExternalPromptPayload = function () {
    return {schema_version:'twwater-site-feedback-v1',batch:{id:this.batch?.public_id||null,title:this.batch?.title||null,revision:Number(this.batch?.revision_number)||0,status:this.batch?.status_code||null},target:{website:'https://aiwork.ddns.net/gary/TWWATER/',program_directory:'C:\\web\\gary\\TWWATER'},requirements:this.items.map(function(item,index){return {sequence:index+1,title:item.structured_title||TOOL_LABELS[item.annotation_type_code]||'畫面調整',annotation_type:item.annotation_type_code||null,modification:cleanPromptText(item.structured_requirement||item.instruction_text||'未填寫',4000),location:{url:item.target_url||null,action:item.target_action||null,viewport:{width:Number(item.viewport_width)||0,height:Number(item.viewport_height)||0},scroll:{x:Number(item.scroll_x)||0,y:Number(item.scroll_y)||0},selector:item.element_selector||null,tag:item.element_tag||null,reference_text:cleanPromptText(item.element_text||'',2000),geometry:item.geometry||{}},acceptance_criteria:Array.isArray(item.acceptance_criteria)&&item.acceptance_criteria.length?item.acceptance_criteria.map(function(value){return cleanPromptText(value,1000);}):['依修改描述與目前畫面驗收']};}),constraints:{task:'依每一項畫面標註完成程式修改；修改前讀取既有程式與安全契約，修改後執行語法、契約及 RWD 測試。',safety:'不要擴大修改範圍；不要自行部署至其他網址、遠端、雲端或外部系統。',location_principle:'同時使用頁面網址、DOM selector、參考文字、viewport、scroll 與框選相對位置交叉確認，不可猜測。',completion:'逐項說明實際修改檔案與位置並列出測試結果；定位資料衝突時停止並回報。'}};
  };
  FeedbackWorkspace.prototype.buildExternalPrompt = function () {
    var self=this,payload=this.buildExternalPromptPayload(),lines=['TWWATER 可直接執行的完整網站修改指令','批次 ID：'+(payload.batch.id||'—')+'｜名稱：'+(payload.batch.title||'—')+'｜Revision：'+payload.batch.revision,'目標：'+payload.target.website+'｜程式目錄：'+payload.target.program_directory,'任務：'+payload.constraints.task,'安全界線：'+payload.constraints.safety,'定位原則：'+payload.constraints.location_principle];
    payload.requirements.forEach(function(requirement){var location=requirement.location,viewport=location.viewport,scroll=location.scroll;lines.push('---','需求 '+requirement.sequence+'｜'+requirement.title+'｜標註類型：'+(TOOL_LABELS[requirement.annotation_type]||requirement.annotation_type||'—'),'修改內容：'+requirement.modification,'畫面定位步驟：開啟 '+(location.url||'—')+'；viewport '+viewport.width+'×'+viewport.height+'；scroll('+scroll.x+','+scroll.y+')；selector「'+(location.selector||'未取得')+'」；<'+(location.tag||'element')+'> 參考文字「'+cleanPromptText(location.reference_text,500)+'」','相對位置：'+self.describeRelativePosition(self.items[requirement.sequence-1]),'技術資料：geometry='+JSON.stringify(location.geometry),'驗收條件：'+requirement.acceptance_criteria.join('；'));});
    lines.push('---','完成條件：'+payload.constraints.completion);if(this.batch?.execution_summary)lines.push('既有完成摘要：'+cleanPromptText(this.batch.execution_summary,2000));return lines.join('\n').replace(/\n{3,}/g,'\n\n');
  };
  FeedbackWorkspace.prototype.downloadExternalPromptJson = function () {
    var payload=this.buildExternalPromptPayload(),blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a'),id=String(this.batch?.public_id||'batch').replace(/[^a-zA-Z0-9-]/g,'');link.href=url;link.download='TWWATER-feedback-'+id+'-revision-'+(Number(this.batch?.revision_number)||0)+'.json';document.body.appendChild(link);link.click();link.remove();setTimeout(function(){URL.revokeObjectURL(url);},0);this.message('JSON 修改需求已下載。');
  };
  FeedbackWorkspace.prototype.buildDiscordConfirmation = function () {
    var lines=['精確規格已整理，可直接交由 Discord 或其他 AI 協作。','批次 ID：'+(this.batch?.public_id||'—'),'批次名稱：'+(this.batch?.title||'—'),'Revision：'+(this.batch?.revision_number||'—'),'目標路徑：C:\\web\\gary\\TWWATER','正式網址：https://aiwork.ddns.net/gary/TWWATER/','修改描述：'];
    this.items.forEach(function(item,index){lines.push((index+1)+'. '+(item.structured_title||item.instruction_text||'未命名需求')+' — '+(item.structured_requirement||item.instruction_text||'未填寫'));});
    lines.push('修改位置：');this.items.forEach(function(item,index){lines.push((index+1)+'. '+(item.target_url||'—')+'；'+(item.element_selector||'未取得 DOM selector')+(item.element_text?'；'+bounded(item.element_text,160):''));});
    lines.push('預計影響：只依上述已整理規格修改 TWWATER；不延伸至其他批次、migration、AppPool recycle、排程、資料庫權限或範圍外部署。');return lines.join('\n');
  };
  FeedbackWorkspace.prototype.findSnapshot = function(kind,target){
    var revision=Number(this.batch?.revision_number)||0;return (this.batch?.snapshots||[]).find(function(entry){return entry.snapshot_kind===kind&&Number(entry.revision_number)===revision&&entry.target_url===target;})||null;
  };
  FeedbackWorkspace.prototype.currentSnapshotTarget = function(){
    if(!this.batch){return null;}var currentTarget=currentSearch(this.nodes.frame),targets=Array.from(new Set(this.items.map(function(item){return item.target_url;}).concat((this.batch.snapshots||[]).map(function(snapshot){return snapshot.target_url;})).filter(Boolean)));return targets.includes(currentTarget)?currentTarget:(targets[0]||'');
  };
  FeedbackWorkspace.prototype.captureCurrentSnapshot = async function(kind){
    if(this.isAnalysisBusy())return;
    if(this.snapshotBusy||this.workflowBusy||this.saveBusy||this.editBusy||this.drag)return;if(!this.batch||!['before','after'].includes(kind)){this.message('截圖種類不正確。',true);return;}if(typeof window.html2canvas!=='function'){this.message('歷史截圖元件尚未載入，請重新整理後再試。',true);return;}
    if(kind==='before'&&(this.pendingDeletes.length||this.items.some(function(item){return item._dirty||item._new;}))){this.snapshotBusy=true;this.renderBatchPreview();var draftSaved=await this.saveAll();this.snapshotBusy=false;this.renderBatchPreview();if(!draftSaved||this.pendingDeletes.length||this.items.some(function(item){return item._dirty||item._new;})){if(draftSaved)this.message('儲存期間需求內容又有變更，請確認內容後再保存截圖。',true);return;}}
    if(this.workflowBusy||this.saveBusy||this.editBusy||this.drag)return;var frameWindow=this.nodes.frame.contentWindow,doc=this.nodes.frame.contentDocument,target=currentSearch(this.nodes.frame);if(!frameWindow||!doc||!doc.documentElement||!this.items.some(function(item){return item.target_url===target;})){this.message('請先切換到此批次有標註的實際頁面。',true);return;}
    this.snapshotBusy=true;this.setWorkflowBusy(true);
    try{
      if(!window.confirm('歷史截圖保存後不可覆寫。請先確認目前畫面沒有密碼、驗證碼、token、個資或其他未遮蔽敏感內容；密碼欄位及標記 data-feedback-snapshot-exclude 的區塊會自動排除。要繼續嗎？'))return;
      this.message(kind==='before'?'正在保存不可覆寫的修改前截圖…':'正在保存不可覆寫的修改後截圖…');if(doc.fonts?.ready)await Promise.race([doc.fonts.ready,new Promise(function(resolve){setTimeout(resolve,3000);})]);
      var width=Math.max(240,Math.round(frameWindow.innerWidth)),height=Math.max(240,Math.round(frameWindow.innerHeight)),scrollX=Math.max(0,Math.round(frameWindow.scrollX)),scrollY=Math.max(0,Math.round(frameWindow.scrollY));
      var captureState={target:target,width:width,height:height,scrollX:scrollX,scrollY:scrollY,editEpoch:this.editEpoch};
      if(width>8192||height>8192||width*height>12000000)throw new Error('目前畫面尺寸過大，請縮小視窗後再保存。');
      var sensitiveSelector='input[type="password"],input[autocomplete="current-password"],input[autocomplete="new-password"],[data-feedback-snapshot-exclude]';
      var canvas=await window.html2canvas(doc.documentElement,{backgroundColor:'#ffffff',allowTaint:false,useCORS:false,logging:false,removeContainer:true,ignoreElements:function(element){try{return element.matches(sensitiveSelector)||Boolean(element.closest('[data-feedback-snapshot-exclude]'));}catch(_){return false;}},width:width,height:height,x:scrollX,y:scrollY,scrollX:0,scrollY:0,windowWidth:width,windowHeight:height,scale:1});
      if(canvas.width!==width||canvas.height!==height)throw new Error('截圖尺寸與目前畫面不一致。');
      if(this.editEpoch!==captureState.editEpoch||this.editBusy||this.pendingDeletes.length||this.items.some(function(item){return item._dirty||item._new;})||currentSearch(this.nodes.frame)!==captureState.target||Math.round(frameWindow.innerWidth)!==captureState.width||Math.round(frameWindow.innerHeight)!==captureState.height||Math.round(frameWindow.scrollX)!==captureState.scrollX||Math.round(frameWindow.scrollY)!==captureState.scrollY)throw new Error('截圖期間頁面或需求已變更，請確認畫面後重新保存。');
      var dataUrl=canvas.toDataURL('image/png');if(!dataUrl.startsWith('data:image/png;base64,'))throw new Error('瀏覽器未產生有效 PNG。');var encoded=dataUrl.slice('data:image/png;base64,'.length);if(encoded.length>7400000)throw new Error('截圖超過 5.5 MB，請縮小視窗或畫面後再保存。');
      var result=await this.api(this.state.urls.snapshot_save,{batch_id:this.batch.public_id,snapshot_kind:kind,revision_number:this.batch.revision_number,target_url:captureState.target,viewport:{width:captureState.width,height:captureState.height,scroll_x:captureState.scrollX,scroll_y:captureState.scrollY},sensitive_content_reviewed:true,image_base64:encoded});
      this.batch=result.batch;this.items=clone(this.batch.items||[]);this.renderAll();this.message(result.already_exists?'相同的不可變歷史截圖已存在。':(kind==='before'?'修改前歷史截圖已保存，之後不可覆寫。':'修改後歷史截圖已保存，之後不可覆寫。'));
    }catch(error){this.message(error?.message||'歷史截圖保存失敗。',true);}finally{this.snapshotBusy=false;this.setWorkflowBusy(false);}
  };
  FeedbackWorkspace.prototype.copyText = async function (text) {
    try{if(navigator.clipboard&&navigator.clipboard.writeText)await navigator.clipboard.writeText(text);else{var area=node('textarea',{});area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();if(!document.execCommand('copy'))throw new Error('copy failed');area.remove();}this.message('內容已複製，可貼到 Discord 或其他 AI 模型。');}catch(_){this.message('瀏覽器不允許自動複製；請展開預覽後手動全選複製。',true);}
  };
  FeedbackWorkspace.prototype.renderSnapshotCard = function(kind,target,status){
    var before=kind==='before',label=before?'修改前':'修改後',snapshot=this.findSnapshot(kind,target),card=node('section',{className:'site-feedback-snapshot-card is-'+kind},[node('h3',{text:label+'截圖'})]);
    if(snapshot){
      var image=node('img',{className:'site-feedback-snapshot-image',src:snapshot.image_url,alt:label+'歷史截圖，Revision '+snapshot.revision_number,loading:'lazy'}),imageLink=node('a',{href:snapshot.image_url,target:'_blank',rel:'noopener',title:'開啟'+label+'原始尺寸截圖'},image),caption=label+'・Revision '+snapshot.revision_number+'・'+snapshot.image_width+'×'+snapshot.image_height;
      card.appendChild(node('figure',{className:'site-feedback-snapshot-figure is-compact'},[imageLink,node('figcaption',{text:caption})]));
      card.appendChild(node('a',{className:'secondary-button site-feedback-snapshot-open',href:snapshot.image_url,target:'_blank',rel:'noopener',text:before?'開啟修改前原圖':'開啟修改後原圖'}));
      return card;
    }
    var oldBefore=before&&!['draft','analysis_failed','rejected'].includes(status),message=oldBefore?'此批次沒有保存修改前截圖；既有歷史畫面無法真實補回。':(before?'尚未保存修改前截圖。':'尚未保存修改後截圖。');
    card.appendChild(node('p',{className:'site-feedback-snapshot-unavailable',text:message}));
    var eligible=!this.batch?.archived_at&&this.items.some(function(item){return item.target_url===target;})&&((before&&['draft','analysis_failed','rejected'].includes(status))||(!before&&status==='completed'&&Boolean(this.state.permissions?.admin)));
    if(eligible)card.appendChild(node('button',{type:'button',className:'secondary-button site-feedback-snapshot-button',dataset:{action:'capture-snapshot',snapshotKind:kind},disabled:Boolean(this.snapshotBusy),text:this.snapshotBusy?'正在保存截圖…':(before?'立即補拍修改前':'立即補拍修改後')}));
    else if(!before&&['awaiting_approval','approved'].includes(status))card.appendChild(node('small',{text:'外部修改完成後按「確認修改已完成」，即可選擇補拍修改後截圖。'}));
    return card;
  };
  FeedbackWorkspace.prototype.renderBatchPreview = function () {
    this.nodes.batchPreview.replaceChildren();if(!this.batch){this.nodes.batchPreview.hidden=true;return;}this.nodes.batchPreview.hidden=false;
    var analysisBusy=this.isAnalysisBusy(),base='?action=feedback&id='+encodeURIComponent(this.batch.public_id),draftTab={className:this.batchView==='draft'?'is-active':'',text:'修改內容'},resultTab={className:this.batchView==='result'?'is-active':'',text:'修改結果'};
    if(analysisBusy){draftTab['aria-disabled']='true';draftTab.tabindex='-1';resultTab['aria-disabled']='true';resultTab.tabindex='-1';}else{draftTab.href=base+'&batch_view=draft';resultTab.href=base+'&batch_view=result';}
    var tabs=node('nav',{className:'site-feedback-batch-preview-tabs','aria-label':'切換需求內容檢視'},[
      node('a',draftTab),
      node('a',resultTab)
    ]),body=node('div',{className:'site-feedback-batch-preview-body'}),currentTarget=this.currentSnapshotTarget(),status=this.batch.status_code,self=this;
    var compare=node('section',{className:'site-feedback-snapshot-compare','aria-label':'修改前後截圖快速比較'});['before','after'].forEach(function(kind){compare.appendChild(self.renderSnapshotCard(kind,currentTarget,status));});
    body.appendChild(compare);
    if(!this.batch?.archived_at&&['awaiting_approval','approved'].includes(status)&&Boolean(this.state.permissions?.admin))body.appendChild(node('section',{className:'site-feedback-current-workflow'},[node('strong',{text:'精確規格已整理，可直接協作'}),node('p',{text:'可直接複製完整指令交由 Discord 或其他 AI 修改；完成後再記錄結果，前後截圖可依需要保存。'}),node('button',{type:'button',className:'primary-button',dataset:{action:'complete'},text:'確認修改已完成'})]));
    if(this.batchView==='result'&&this.batch.execution_summary)body.appendChild(node('p',{className:'site-feedback-execution-summary',text:this.batch.execution_summary}));
    var editable=this.isEditable(),aiReady=this.aiPromptReady();
    body.appendChild(node('h3',{className:'site-feedback-summary-title',text:this.batchView==='draft'?'修改說明（可直接填寫）':'修改說明'}));
    var list=node('ol',{className:'site-feedback-inline-requirements'});this.items.forEach(function(item,index){
      var selected=self.selectedId===String(item.public_id),heading=node('strong',{text:(index+1)+'. '+(self.batchView==='result'?(item.structured_title||'修改項目'):(TOOL_LABELS[item.annotation_type_code]||'標註'))});if(selected)heading.appendChild(node('span',{className:'site-feedback-selection-chip',text:'目前選取'}));
      var itemProps={className:selected?'is-selected':'','aria-current':selected?'true':'false',dataset:{itemId:String(item.public_id)}};
      if(self.batchView==='result'){list.appendChild(node('li',itemProps,[heading,node('p',{text:item.structured_requirement||'尚未產生精確規格'})]));return;}
      var isText=item.annotation_type_code==='text',textarea=node('textarea',{className:'site-feedback-inline-instruction',maxlength:'2000',placeholder:isText?'輸入文字內容或這個位置要修改什麼。':'直接寫這個位置要修改什麼。',disabled:Boolean(self.saveBusy||self.workflowBusy||analysisBusy),'aria-label':'第 '+(index+1)+' 項修改說明'});textarea.readOnly=!editable;textarea.value=item.instruction_text||'';textarea.addEventListener('input',function(){fitInstructionTextarea(textarea);if(!self.isEditable()||self.saveBusy||self.workflowBusy)return;item.instruction_text=textarea.value;item._edit_version=(Number(item._edit_version)||0)+1;item._dirty=true;self.editEpoch++;self.invalidateAiPrompt();if(isText)self.renderOverlay();});requestAnimationFrame(function(){fitInstructionTextarea(textarea);});list.appendChild(node('li',itemProps,[heading,textarea]));
    });
    if(this.items.length)body.appendChild(list);else body.appendChild(node('p',{className:'muted',text:'此批次尚無修改說明。請先在中央畫面建立標註。'}));
    var actions=node('div',{className:'site-feedback-summary-actions'},[node('button',{type:'button',className:'secondary-button',dataset:{action:'open-details'},text:this.batch?.archived_at?'檢視標註細節':(this.batchView==='draft'?'檢視／修改細部內容':'檢視標註細節')})]);
    if(this.batchView==='draft'&&editable)actions.appendChild(node('button',{type:'button',className:'secondary-button',dataset:{action:'save'},disabled:Boolean(this.saveBusy||this.workflowBusy||analysisBusy||this.items.length===0),text:'儲存內容'}));
    if(this.batchView==='draft'&&(editable||analysisBusy)){var submitButton=node('button',{type:'button',className:'primary-button site-feedback-analysis-submit',dataset:{action:'submit'},disabled:Boolean(this.saveBusy||this.workflowBusy||analysisBusy||this.items.length===0)});if(analysisBusy){submitButton.appendChild(node('span',{className:'site-feedback-spinner is-button','aria-hidden':'true'}));submitButton.appendChild(node('span',{text:'正在整理精確規格…'}));}else submitButton.textContent='整理精確規格';actions.appendChild(submitButton);}
    actions.appendChild(node('button',{type:'button',className:'secondary-button',dataset:{copyKind:'external'},disabled:Boolean(!aiReady),text:aiReady?'複製完整修改指令':'請先整理精確規格'}));actions.appendChild(node('button',{type:'button',className:'secondary-button',dataset:{action:'download-json'},disabled:Boolean(!aiReady),text:'下載修改需求 JSON'}));
    if(['awaiting_approval','approved','completed'].includes(this.batch.status_code))actions.appendChild(node('button',{type:'button',className:'secondary-button',dataset:{copyKind:'discord'},disabled:Boolean(!aiReady),text:'複製 Discord 協作文字'}));
    body.appendChild(actions);this.nodes.batchPreview.appendChild(tabs);this.nodes.batchPreview.appendChild(body);
  };
  FeedbackWorkspace.prototype.renderDetailPrompt = function () {
    this.nodes.detailPrompt.replaceChildren();if(!this.batch)return;
    if(this.batchView==='result'&&this.items.length){var resultDetails=node('section',{className:'site-feedback-detail-result'},[node('h3',{text:'完整修改規格'})]),resultList=node('ol',{});this.items.forEach(function(item,index){var content=[node('strong',{text:(index+1)+'. '+(item.structured_title||'修改項目')}),node('p',{text:item.structured_requirement||'尚未產生精確規格'})];if(Array.isArray(item.acceptance_criteria)&&item.acceptance_criteria.length){var criteria=node('ul',{className:'site-feedback-preview-criteria'});item.acceptance_criteria.forEach(function(value){criteria.appendChild(node('li',{text:String(value)}));});content.push(criteria);}resultList.appendChild(node('li',{},content));});resultDetails.appendChild(resultList);this.nodes.detailPrompt.appendChild(resultDetails);}
    if(!this.aiPromptReady()){this.nodes.detailPrompt.appendChild(node('p',{className:'site-feedback-ai-waiting',text:'請先在右側按「整理精確規格」；整理完成後即可預覽、複製或下載。'}));return;}
    var externalPreview=node('textarea',{readonly:'readonly','aria-label':'其他 AI 修改提示預覽'});externalPreview.value=this.buildExternalPrompt();
    var externalTechnical=node('details',{className:'site-feedback-technical-location'},[node('summary',{text:'技術定位資料與完整 AI 提示詞預覽（一般不需閱讀）'}),externalPreview]);
    this.nodes.detailPrompt.appendChild(node('section',{className:'site-feedback-copy-tool is-open'},[node('h3',{text:'AI 修改提示詞'}),node('p',{text:'系統已自動整理批次、修改內容、技術定位、安全界線與驗收條件；一般不需要閱讀技術資料，直接複製即可。'}),node('div',{className:'site-feedback-copy-actions'},[node('button',{type:'button',className:'primary-button',dataset:{copyKind:'external'},text:'複製完整 AI 修改提示'}),node('button',{type:'button',className:'secondary-button',dataset:{action:'download-json'},text:'下載 JSON'})]),externalTechnical]));
    if(['awaiting_approval','approved','completed'].includes(this.batch.status_code)){var discordPreview=node('textarea',{readonly:'readonly','aria-label':'Discord 協作文字預覽'});discordPreview.value=this.buildDiscordConfirmation();this.nodes.detailPrompt.appendChild(node('section',{className:'site-feedback-copy-tool is-open'},[node('h3',{text:'Discord 協作文字'}),discordPreview,node('button',{type:'button',className:'secondary-button',dataset:{copyKind:'discord'},text:'複製 Discord 協作文字'})]));}
  };
  FeedbackWorkspace.prototype.renderList = function () {
    var self=this,editable=this.isEditable(),analysisBusy=this.isAnalysisBusy();this.nodes.annotationList.replaceChildren();
    if(this.batchView==='result')return;
    if(!this.batch){this.nodes.annotationList.appendChild(node('li',{className:'site-feedback-empty',text:'請先新增需求批次。'}));return;}
    if(this.items.length===0){this.nodes.annotationList.appendChild(node('li',{className:'site-feedback-empty',text:'選擇框選、箭頭、螢光、文字或編號，在中央實際頁面上建立第一條需求。'}));return;}
    this.items.forEach(function(item,index){
      var id=String(item.public_id),isText=item.annotation_type_code==='text';
      var textarea=node('textarea',{maxlength:'2000',placeholder:isText?'輸入要顯示在標註框中的文字，或寫下這個位置的修改內容。':'直接寫這個位置要改什麼，例如：字體放大到 18px，並減少左右留白。',disabled:Boolean(self.saveBusy||self.workflowBusy||analysisBusy)});textarea.readOnly=!editable;textarea.value=item.instruction_text||'';
      textarea.addEventListener('input',function(){fitInstructionTextarea(textarea);if(!self.isEditable()||self.saveBusy||self.workflowBusy)return;item.instruction_text=textarea.value;item._edit_version=(Number(item._edit_version)||0)+1;item._dirty=true;self.editEpoch++;self.invalidateAiPrompt();if(isText)self.renderOverlay();});requestAnimationFrame(function(){fitInstructionTextarea(textarea);});
      var instructionField=node('label',{className:'site-feedback-instruction-field'},[node('span',{text:isText?'文字內容／修改說明':'修改說明'}),textarea]);
      var headingLabel=(TOOL_LABELS[item.annotation_type_code]||item.annotation_type_code);if(item.annotation_type_code==='number')headingLabel='編號 '+self.geometryApi.numberOrdinal(self.items,id);
      var heading=node('div',{className:'site-feedback-annotation-heading'},[node('button',{type:'button',className:'site-feedback-item-select',text:(index+1)+'. '+headingLabel}),node('small',{text:item.target_action||''})]);
      var parentSelect=node('select',{'data-parent-select':'','aria-label':'選擇這項需求要歸在哪個主要問題下面',disabled:Boolean(!editable||self.saveBusy||self.workflowBusy||analysisBusy)});parentSelect.appendChild(node('option',{value:'',text:'獨立問題（預設）'}));
      self.items.forEach(function(candidate,candidateIndex){if(candidate._new||String(candidate.public_id)===id)return;parentSelect.appendChild(node('option',{value:String(candidate.public_id),text:'歸到 '+(candidateIndex+1)+'. '+bounded(candidate.instruction_text||candidate.structured_title||'未命名需求',42)}));});
      parentSelect.value=item.parent_public_id||'';parentSelect.addEventListener('change',function(){if(!self.isEditable()||self.saveBusy||self.workflowBusy)return;item.parent_public_id=parentSelect.value||null;item._edit_version=(Number(item._edit_version)||0)+1;item._dirty=true;self.editEpoch++;self.invalidateAiPrompt();});
      var parentField=node('label',{className:'site-feedback-parent-field'},[node('span',{text:'歸到哪一個主要問題下面'}),parentSelect]);
      var relationDetails=node('details',{className:'site-feedback-parent-details'},[node('summary',{text:'關聯到其他問題（選填）'}),parentField,node('small',{text:'一般情況保持「獨立問題」即可；只有這一項是另一項的細節時才需要設定。'})]);if(item.parent_public_id)relationDetails.open=true;
      var structure=[];if(item.structured_requirement){structure.push(node('strong',{text:item.structured_title||'Hermes 精確需求'}));structure.push(node('p',{text:item.structured_requirement}));if(Array.isArray(item.acceptance_criteria))item.acceptance_criteria.forEach(function(c){structure.push(node('li',{text:String(c)}));});}
      var structured=node('div',{className:'site-feedback-structured',hidden:!item.structured_requirement},structure);
      var li=node('li',{className:'site-feedback-annotation-item'+(item.parent_public_id?' is-child':'')+(self.selectedId===id?' is-selected':''),'aria-current':self.selectedId===id?'true':'false',dataset:{itemId:id}},[heading,instructionField,relationDetails,structured]);self.nodes.annotationList.appendChild(li);
    });
  };
  FeedbackWorkspace.prototype.itemPixels = function (item) {
    var rect=this.nodes.overlay.getBoundingClientRect(),frameWindow=this.nodes.frame.contentWindow;
    var saved={width:Number(item.viewport_width)||rect.width,height:Number(item.viewport_height)||rect.height,scroll_x:Number(item.scroll_x)||0,scroll_y:Number(item.scroll_y)||0};
    var current={width:Math.max(1,Number(frameWindow?.innerWidth)||rect.width),height:Math.max(1,Number(frameWindow?.innerHeight)||rect.height),scroll_x:Math.max(0,Number(frameWindow?.scrollX)||0),scroll_y:Math.max(0,Number(frameWindow?.scrollY)||0)};
    return this.geometryApi.geometryToViewportPixels(item.annotation_type_code,item.geometry,saved,current);
  };
  FeedbackWorkspace.prototype.renderOverlay = function () {
    var self=this;Array.from(this.nodes.overlay.querySelectorAll('.site-feedback-shape:not(.is-draft), .site-feedback-number-label, .site-feedback-text-label')).forEach(function(el){el.remove();});
    if(this.batchView==='result')return;
    var target=currentSearch(this.nodes.frame);
    this.items.filter(function(item){return item.target_url===target;}).forEach(function(item){
      var p=self.drag?.mode==='move'&&self.drag.itemId===String(item.public_id)?self.drag.previewPixels:self.itemPixels(item),shape,label;
      if(item.annotation_type_code==='arrow')shape=svgNode('line',{x1:p.x1,y1:p.y1,x2:p.x2,y2:p.y2,class:'site-feedback-shape is-arrow','marker-end':'url(#site-feedback-arrowhead)','data-item-id':item.public_id});
      else if(item.annotation_type_code==='number'){shape=svgNode('circle',{cx:p.left+p.width/2,cy:p.top+p.height/2,r:Math.min(p.width,p.height)/2,class:'site-feedback-shape is-number','data-item-id':item.public_id});label=svgNode('text',{x:p.left+p.width/2,y:p.top+p.height/2,class:'site-feedback-number-label','text-anchor':'middle','dominant-baseline':'central','data-item-id':item.public_id});label.textContent=String(self.geometryApi.numberOrdinal(self.items,item.public_id));}
      else {shape=svgNode('rect',{x:p.left,y:p.top,width:p.width,height:p.height,class:'site-feedback-shape is-'+item.annotation_type_code,'data-item-id':item.public_id});if(item.annotation_type_code==='text'){label=svgNode('text',{x:p.left+7,y:p.top+22,class:'site-feedback-text-label','data-item-id':item.public_id});label.textContent=bounded(item.instruction_text||'輸入文字',80);}}
      if(self.selectedId===String(item.public_id))shape.classList.add('is-selected');self.nodes.overlay.appendChild(shape);if(label)self.nodes.overlay.appendChild(label);
    });
    this.setTool(this.tool);
  };
  FeedbackWorkspace.prototype.renderStatus = function () {
    var status=this.batch?.status_code||'',analysisBusy=this.isAnalysisBusy(),locked=this.saveBusy||this.workflowBusy||analysisBusy,editable=this.isEditable(),statusLabel=STATUS_LABELS[status]||status||'—';
    this.nodes.batchTitle.textContent=this.batch?.title||'尚未建立需求批次';this.nodes.batchStatus.textContent=this.batch?.archived_at?'已封存・原狀態：'+statusLabel:statusLabel;
    this.nodes.workspace.setAttribute('aria-busy',analysisBusy?'true':'false');this.nodes.analysisBusyNotice.hidden=!analysisBusy;
    if(analysisBusy){var queued=status==='queued_analysis';this.nodes.analysisBusyTitle.textContent=queued?'精確規格正在排程':'AI正在整理精確規格';this.nodes.analysisBusyText.textContent=queued?'系統已收到這批修改需求，正在等待AI處理，請稍候。':'正在整理修改內容、位置與驗收條件。完成後會自動更新本頁。';this.nodes.stageStatus.textContent='正在整理精確規格，暫時無法修改或切換此批次。';}
    this.nodes.pageSelect.disabled=locked||!this.batch;this.nodes.annotationToolGroup.querySelectorAll('button').forEach(function(control){control.disabled=locked||(!editable&&control.dataset.tool!=='browse');});this.nodes.editToolGroup.querySelectorAll('button').forEach(function(control){control.disabled=locked||!editable;});
    this.nodes.frame.inert=locked;this.nodes.frame.style.pointerEvents=locked?'none':'';this.nodes.overlay.style.pointerEvents=locked?'none':'';
    if(this.nodes.createTrigger)this.nodes.createTrigger.disabled=analysisBusy;if(this.nodes.historyTrigger)this.nodes.historyTrigger.disabled=analysisBusy;
    if(this.batch?.analysis_summary){this.nodes.summary.hidden=false;this.nodes.summary.replaceChildren(node('h3',{text:'規格產生摘要'}),node('p',{text:this.batch.analysis_summary}));}else this.nodes.summary.hidden=true;
  };

  FeedbackWorkspace.prototype.deleteSelection = function () { if(!this.isEditable()||this.saveBusy||this.workflowBusy||!this.selectedId)return;var index=this.items.findIndex(function(i){return String(i.public_id)===String(this.selectedId);},this);if(index<0)return;this.snapshot();var item=this.items[index];if(!item._new)this.pendingDeletes.push(item.public_id);this.items.splice(index,1);this.editEpoch++;this.selectedId=null;this.renderAll(); };

  FeedbackWorkspace.prototype.archiveBatch = async function(batchId,restore) {
    if(this.workflowBusy||this.isAnalysisBusy())return;var batch=(this.state.batches||[]).find(function(entry){return String(entry.public_id)===String(batchId);});if(!batch)return;
    var prompt=restore?'解除封存後，這筆資料會重新出現在「目前歷程」。\n\n批次：'+batch.title+'\n批次 ID：'+batch.public_id+'\n\n要解除封存嗎？':'封存後資料不會刪除，需求、截圖及稽核紀錄都會保留；之後只有點擊「封存資料」才會顯示。\n\n批次：'+batch.title+'\n批次 ID：'+batch.public_id+'\n\n要封存嗎？';
    if(!window.confirm(prompt))return;this.setWorkflowBusy(true);
    try{await this.api(restore?this.state.urls.restore:this.state.urls.archive,{batch_id:batch.public_id});window.location.href=restore?'?action=feedback&history=archived':'?action=feedback';}
    catch(error){this.setWorkflowBusy(false);this.message(error.message||'封存狀態更新失敗。',true);}
  };

  FeedbackWorkspace.prototype.api = async function (url,payload) {
    var response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':this.state.csrf_token},body:JSON.stringify(Object.assign({csrf_token:this.state.csrf_token},payload))});
    var result=await response.json().catch(function(){return {ok:false,error:'伺服器回應格式不正確。'};});if(!response.ok||!result.ok)throw new Error(result.error||'操作失敗。');return result;
  };
  FeedbackWorkspace.prototype.createBatch = function () { if(this.isAnalysisBusy())return;this.openStartDialog(); };
  FeedbackWorkspace.prototype.saveAll = function () {
    if(this.isAnalysisBusy())return Promise.resolve(false);
    if(this.savePromise)return this.savePromise;
    if(!this.batch){this.message('請先建立需求批次。',true);return Promise.resolve(false);}if(this.items.some(function(i){return !bounded(i.instruction_text,2000);})){this.message('每一個標註都要填寫「要改什麼」。',true);return Promise.resolve(false);}
    var self=this;this.saveBusy=true;this.renderAll();
    this.savePromise=Promise.resolve().then(async function(){
      try{
        self.message('正在儲存需求…');
        while(self.pendingDeletes.length){var deleteResult=await self.api(self.state.urls.delete_item,{batch_id:self.batch.public_id,item_id:self.pendingDeletes[0]});if(!deleteResult.batch)throw new Error('伺服器未回傳刪除後的批次狀態。');self.batch=deleteResult.batch;self.pendingDeletes.shift();}
        for(var item of self.items.filter(function(i){return i._dirty||i._new;})){
          var savedVersion=Number(item._edit_version)||0;
          var result=await self.api(self.state.urls.save_item,{batch_id:self.batch.public_id,item_id:item._new?'':item.public_id,parent_id:item.parent_public_id||'',target_url:item.target_url,annotation_type:item.annotation_type_code,geometry:item.geometry,viewport:{width:item.viewport_width,height:item.viewport_height,scroll_x:item.scroll_x,scroll_y:item.scroll_y,dpr:item.device_pixel_ratio},page_fingerprint:item.page_fingerprint||'',selector:item.element_selector||'',element_tag:item.element_tag||'',element_text:item.element_text||'',element_fingerprint:item.element_fingerprint||'',instruction:item.instruction_text});
          if(!result.saved_item_id)throw new Error('伺服器未回傳已儲存的需求識別碼。');var oldId=String(item.public_id);item.public_id=result.saved_item_id;if(self.selectedId===oldId)self.selectedId=result.saved_item_id;item._new=false;if((Number(item._edit_version)||0)===savedVersion)item._dirty=false;else item._dirty=true;item.structured_title=null;item.structured_requirement=null;item.acceptance_criteria=[];item.risk_level=null;self.batch=result.batch;
        }
        self.history=[];self.future=[];self.message('修改內容已儲存，AI 修改提示詞與技術定位資料已重新彙整。');return true;
      }catch(e){self.message(e.message,true);return false;}
    }).finally(function(){self.saveBusy=false;self.savePromise=null;self.renderAll();});
    return this.savePromise;
  };
  FeedbackWorkspace.prototype.submitBatch = async function () {
    if(this.workflowBusy||this.snapshotBusy||this.editBusy||this.drag||this.isAnalysisBusy())return;var saved=await this.saveAll();if(!saved||this.pendingDeletes.length||this.items.some(function(i){return i._dirty||i._new;}))return;
    if(this.workflowBusy||this.snapshotBusy||this.editBusy||this.drag||this.isAnalysisBusy())return;this.setWorkflowBusy(true);var submitEpoch=this.editEpoch;
    try{
      if(!window.confirm('「整理精確規格」只會把整批標註整理成清楚的修改描述、位置與驗收條件，不會自行修改或部署網站。整理完成後可直接複製交由 Discord 或其他 AI 協作。要繼續嗎？'))return;
      if(this.editEpoch!==submitEpoch||this.editBusy||this.pendingDeletes.length||this.items.some(function(i){return i._dirty||i._new;}))throw new Error('確認期間需求內容已變更，請重新確認後再送出。');
      var result=await this.api(this.state.urls.submit,{batch_id:this.batch.public_id});this.batch=result.batch;this.items=clone(result.batch.items||[]);this.renderAll();this.message('已開始整理精確規格；完成後即可在右側直接預覽、複製或下載。');this.pollIfNeeded();
    }catch(e){this.message(e.message,true);}finally{this.setWorkflowBusy(false);}
  };
  FeedbackWorkspace.prototype.completeBatch = async function () {
    if(this.workflowBusy||this.snapshotBusy||this.saveBusy||this.editBusy||this.drag||!['awaiting_approval','approved'].includes(this.batch?.status_code||'')||!this.state.permissions?.admin)return;
    var summary=window.prompt('請簡短記錄外部協作已完成的修改與驗證結果。這個動作只更新歷程，不會由網站執行程式或部署：',this.batch.execution_summary||'已依整理後規格完成修改並驗證。');
    if(summary===null)return;summary=bounded(summary,4000);if(!summary){this.message('請填寫完成摘要。',true);return;}
    if(!window.confirm('確認 Discord 作業已經完成嗎？\n\n此動作會把批次標記為「已完成」，之後可保存不可覆寫的修改後截圖；網站不會執行任何程式或部署。'))return;
    this.setWorkflowBusy(true);
    try{var result=await this.api(this.state.urls.complete,{batch_id:this.batch.public_id,summary:summary});this.batch=result.batch;this.items=clone(result.batch.items||[]);this.batchView='result';this.renderAll();this.message('已記錄修改完成；請在「修改後截圖」按下「立即補拍修改後」。');}
    catch(e){this.message(e.message||'無法記錄完成狀態。',true);}finally{this.setWorkflowBusy(false);}
  };
  FeedbackWorkspace.prototype.pollIfNeeded = function () {
    if(!this.isAnalysisBusy()||!this.batch){this.stopPolling();return;}
    if(this.pollTimer!==null||this.pollInFlight)return;
    var self=this;
    var batchId=String(this.batch.public_id);
    var revision=Number(this.batch.revision_number);
    var generation=++this.pollGeneration;
    this.pollTimer=setTimeout(async function(){
      self.pollTimer=null;
      self.pollInFlight=true;
      try{
        var result=await self.api(self.state.urls.status,{batch_id:batchId});
        if(generation!==self.pollGeneration||String(self.batch?.public_id||'')!==batchId||Number(self.batch?.revision_number)!==revision)return;
        if(!result.ok||!result.batch)throw new Error('狀態回應不完整。');
        self.batch=result.batch;self.items=clone(result.batch.items||[]);self.renderAll();
        if(!self.isAnalysisBusy()){
          if(self.batch.status_code==='analysis_failed')self.message('精確規格整理失敗，可修正內容後重新整理。',true);
          else self.message('精確規格整理完成，可以複製或下載。');
        }
      }catch(_){
        if(generation===self.pollGeneration&&String(self.batch?.public_id||'')===batchId&&Number(self.batch?.revision_number)===revision)self.message('狀態更新暫時失敗，AI仍在背景處理；稍後會自動重試。');
      }finally{
        self.pollInFlight=false;
        if(self.isAnalysisBusy())self.pollIfNeeded();
      }
    },3000);
  };
  FeedbackWorkspace.prototype.message = function (text,error) { this.nodes.message.textContent=String(text||'');this.nodes.message.classList.toggle('is-error',Boolean(error)); };

  async function start() {
    var root=document.querySelector('[data-site-feedback-app]');var stateNode=document.getElementById('site-feedback-state');if(!root||!stateNode)return;
    try{var state=JSON.parse(stateNode.textContent||'{}');var geometry=await import('./site-feedback-geometry.mjs');new FeedbackWorkspace(root,state,geometry).init();}
    catch(error){root.replaceChildren(node('section',{className:'panel narrow'},[node('h2',{text:'畫面修改需求載入失敗'}),node('p',{text:error.message||'請稍後再試。'})]));}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
