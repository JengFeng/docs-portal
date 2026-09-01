<?php
declare(strict_types=1);
function parity_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$root=dirname(__DIR__);$php=(string)file_get_contents($root.'/app/image_library.php');$js=(string)file_get_contents($root.'/assets/image-library.js');$feedbackCss=(string)file_get_contents($root.'/assets/site-feedback.css');
parity_assert(str_contains($php,'<h1>AI 圖像／資訊圖表修改需求</h1>')&&str_contains($php,"portal_render_page('AI 圖像／資訊圖表修改需求'"),'IMAGE REVIEW visible heading and browser title must use the requested AI image/infographic wording');
parity_assert(str_contains($php,'可直接在 AI 圖像／資訊圖表上框選')&&str_contains($php,'先封存目前原圖，再直接更新正式圖片'),'IMAGE REVIEW intro description must describe annotation plus direct archive-and-replace workflow');
parity_assert(str_contains($feedbackCss,'.image-review-page .site-feedback-inline-requirements>li{box-sizing:border-box;grid-template-columns:minmax(0,1fr);min-width:0;width:100%;max-width:100%;}'),'IMAGE REVIEW compact requirement cards must expose one definite full-width grid track');
parity_assert(str_contains($feedbackCss,'.image-review-page .site-feedback-inline-requirements .site-feedback-inline-instruction{box-sizing:border-box;display:block;justify-self:stretch;inline-size:100%!important;width:100%!important;min-inline-size:100%!important;min-width:100%!important;max-inline-size:100%!important;'),'IMAGE REVIEW compact textareas must defeat the screenshot-reproduced 175px used width and strictly fill their card');
parity_assert(!str_contains($php,'class="back-link"'),'reference-parity page must not add a back-link row above the shared intro');
parity_assert(!str_contains($php,'>下載原圖</a>'),'reference-parity intro actions must contain only history and new-batch actions');
parity_assert(str_contains($php,'>修改歷程</button>')&&str_contains($php,'>新增需求批次</button>'),'intro action labels must exactly match the reference page');
parity_assert(str_contains($php,'class="site-feedback-toolbar-group"><label class="site-feedback-page-picker">目前圖片<select'),'toolbar must begin with a fixed-width current-image picker matching the reference page picker');
parity_assert(!str_contains($php,'data-image-action="zoom-out"')&&!str_contains($php,'data-image-action="zoom-in"')&&!str_contains($php,'data-image-action="zoom-reset"'),'reference toolbar must not include an extra visible zoom group');
parity_assert(str_contains($php,'class="site-feedback-tool-button" data-image-tool='),'annotation buttons must use the exact shared tool-button component');
$panelTokens=['site-feedback-batch-preview-tabs','修改內容','修改結果','修改說明（可直接填寫）','site-feedback-inline-requirements','檢視／修改細部內容','儲存內容','整理精確規格','請先整理精確規格','下載修改需求 JSON'];
$cursor=-1;foreach($panelTokens as $token){$at=strpos($php,$token,$cursor+1);parity_assert($at!==false,'missing reference right-panel element: '.$token);parity_assert($at>$cursor,'reference right-panel element order mismatch: '.$token);$cursor=$at;}
foreach(['site-feedback-snapshot-compare','目前正式圖片','圖片版本封存'] as $resultToken)parity_assert(str_contains($php,$resultToken),'current-result card must preserve reference snapshot structure: '.$resultToken);
parity_assert(str_contains($php,". \$snapshotHtml\n        . '<h3 class=\"site-feedback-summary-title\""),'current result card must be inserted between tabs and the requirement summary');
foreach(['site-feedback-batch-list','site-feedback-batch-list-row','site-feedback-batch-list-summary','site-feedback-batch-sequence','site-feedback-batch-identity','site-feedback-batch-meta','site-feedback-batch-row-actions'] as $class)parity_assert(str_contains($js,$class),'history renderer must use exact reference class: '.$class);
foreach(['選取此 Revision','無修改前截圖','無修改後截圖','複製 ID'] as $label)parity_assert(str_contains($js,$label),'history renderer missing explicit or honest action: '.$label);
parity_assert(str_contains($js,".sort((a,b)=>String(b.created_at||'').localeCompare(String(a.created_at||'')))"),'history rows must sort newest created_at first regardless of API array order');
parity_assert(str_contains($php,"'wide-readable-page site-feedback-page',true"),'IMAGE REVIEW body scope must match the reference page width and RWD scope');
parity_assert(str_contains($js,"revisionState={...revisionCollaboration.initialize(revisions,root.dataset.sourceHash||''),selectedRevision:null}"),'fresh IMAGE REVIEW entry must remain editing instead of auto-selecting an old immutable Revision');
parity_assert(str_contains($js,"row.open=selected||(!revisionState.selectedRevision&&index===0)")&&!str_contains($js,"summary.addEventListener('click'"),'history expansion must remain inspection-only; selection requires the explicit action button');
parity_assert(str_contains($js,"querySelectorAll('[data-image-view]')")&&str_contains($js,"data-image-view"),'draft/result tabs must be interactive');
parity_assert(str_contains($js,"list.querySelectorAll('textarea')")&&str_contains($js,"list.querySelector('li:last-child textarea')")&&!str_contains($js,"list.querySelectorAll('.image-annotation-item textarea')")&&!str_contains($js,"list.querySelector('.image-annotation-item:last-child textarea')"),'blank-note validation and new-annotation focus must target the new compact right-panel textareas');
parity_assert(str_contains($js,"[data-image-history-view]').forEach((button)=>button.addEventListener('click',(event)=>{event.preventDefault();"),'anchor-based history tabs must prevent page jumps');
echo "[OK] IMAGE REVIEW exact reference-UI structure contract passed.\n";
