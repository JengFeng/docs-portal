<?php
declare(strict_types=1);
function unified_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"[FAIL] $message\n");exit(1);}}
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/index.php');
$module=(string)file_get_contents($root.'/app/image_library.php');
$js=(string)file_get_contents($root.'/assets/image-library.js');
$css=(string)file_get_contents($root.'/assets/app.css');
$permissions=(string)file_get_contents($root.'/database/004_app_permissions.template.sql');
$migration=is_file($root.'/database/023_image_requirement_revisions.sql')?(string)file_get_contents($root.'/database/023_image_requirement_revisions.sql'):'';

$adminRoutes="'visual_assets','image_review','image_annotations_save','image_annotation_status','image_export','image_requirement_revision_create','image_requirement_revision_status','image_requirement_revision_archive'";
unified_assert(str_contains($index,$adminRoutes),'central administrator gate must contain every image collaboration route including revision create/status/archive');
unified_assert(str_contains($index,"'image_asset'")&&!str_contains($adminRoutes,"image_asset"),'private hash-bound image_asset must remain authenticated but outside the administrator collaboration gate');
foreach(['portal_handle_image_requirement_revision_create','portal_handle_image_requirement_revision_status','portal_handle_image_requirement_revision_archive'] as $handler) unified_assert(str_contains($index,$handler),'missing central route dispatch: '.$handler);

$labels=['儲存內容','整理精確規格','請先整理精確規格','下載修改需求 JSON','確認修改已完成'];
$cursor=-1;foreach($labels as $label){$position=strpos($module,$label,$cursor+1);unified_assert($position!==false,'missing reference right-panel action: '.$label);unified_assert($position>$cursor,'right-panel action order differs from reference workspace: '.$label);$cursor=$position;}
unified_assert(str_contains($js,"copyHermesPrompt.textContent=copyReady?'複製完整修改指令':'請先整理精確規格'"),'ready revision must switch the reference copy action to 複製完整修改指令');
foreach(['site-feedback-intro','site-feedback-intro-actions','site-feedback-toolbar','site-feedback-workspace','site-feedback-review-panel','site-feedback-panel-heading','site-feedback-batch-dialog','site-feedback-detail-dialog'] as $sharedClass) unified_assert(str_contains($module,$sharedClass),'IMAGE REVIEW must reuse site-feedback visual component: '.$sharedClass);
$historyPosition=strpos($module,'data-image-revision-history');$newPosition=strpos($module,'data-image-new-request');$workspacePosition=strpos($module,'<section data-image-review');
unified_assert($historyPosition!==false&&$newPosition!==false&&$workspacePosition!==false&&$historyPosition<$newPosition&&$newPosition<$workspacePosition,'page header must expose history then new-batch before the image workspace');
unified_assert(str_contains($module,'>修改歷程</button>')&&str_contains($module,'>新增需求批次</button>'),'page header action labels must match the reference visual-requirement page');
unified_assert(str_contains($module,"'wide-readable-page site-feedback-page',true"),'IMAGE REVIEW must load the exact reference page width and shared site-feedback stylesheet scope');
unified_assert(str_contains($js,"querySelector('[data-image-new-request]')")&&str_contains($js,"setTool('rectangle')"),'new image requirement action must start direct rectangle annotation without clearing saved work');
preg_match_all('/data-image-revision-history(?:\\s|>)/',$module,$historyTriggers);unified_assert(count($historyTriggers[0])===1,'history trigger must exist once in the page header, not inside the right-panel action list');
unified_assert(str_contains($module,'data-image-revision-create disabled')&&str_contains($module,'data-image-revision-copy disabled')&&str_contains($module,'data-image-revision-download disabled')&&str_contains($module,'data-image-revision-complete disabled'),'revision controls must render fail-closed');
unified_assert(str_contains($module,'data-image-revision-history')&&str_contains($module,'目前歷程')&&str_contains($module,'封存資料'),'history must expose active and archived views');
unified_assert(str_contains($module,'data-image-revision-details')&&str_contains($module,'<details')&&!preg_match('/<details[^>]*data-image-revision-details[^>]*\sopen(?:\s|>)/',$module),'technical revision details must start closed');
unified_assert(str_contains($module,'先封存目前原圖')&&str_contains($module,'直接更新正式圖片'),'direct image workflow notice must explain archive-before-replace');
unified_assert(str_contains($css,'.image-revision-history-dialog')&&str_contains($css,'@media (max-width: 900px)')&&str_contains($css,'@media (max-width: 640px)'),'unified panel/history must have desktop, tablet and mobile styles');

foreach(['portal_image_requirement_revision_list','portal_image_requirement_revision_create','portal_image_requirement_revision_set_status','portal_image_requirement_revision_set_archived','portal_image_requirement_build'] as $function) unified_assert(str_contains($module,'function '.$function),'missing revision domain function: '.$function);
unified_assert(str_contains($module,"status_code = 'open'")&&str_contains($module,'WITH (UPDLOCK, HOLDLOCK)'),'create must snapshot saved open annotations and allocate revision under a transaction lock');
unified_assert(str_contains($module,'hash_file(\'sha256\'')&&str_contains($module,'portal_get_catalog_document'),'create must recheck source handle/hash against catalog');
unified_assert(str_contains($js,'TWImageRevisionCollaboration')&&str_contains($js,'selectedRevision'),'client must expose immutable selected-revision collaboration projection');
unified_assert(str_contains($js,'current_source')||str_contains($js,'currentSource'),'client must distinguish old-source history from current source');

unified_assert(str_contains($migration,'CREATE TABLE dbo.image_requirement_revisions'),'migration must create the single revision table');
unified_assert(!str_contains($migration,'CREATE TABLE dbo.image_requirement_revision_items'),'migration must not create a child item table');
foreach(['requirement_json','prompt_text','item_count','status_code','completed_by_user_id','completed_at','archived_by_user_id','archived_at','rowver'] as $column) unified_assert(str_contains($migration,$column),'migration missing required field '.$column);
unified_assert(str_contains($migration,'DENY DELETE ON dbo.image_requirement_revisions')&&str_contains($permissions,'DENY DELETE ON dbo.image_requirement_revisions'),'formal and template permissions must deny physical delete');
unified_assert(str_contains($migration,'TR_image_requirement_revisions_immutable')&&str_contains($migration,'immutable'),'DB must enforce immutable revision content while allowing lifecycle metadata updates');

foreach(['image_revision_jobs','GPT','schema worker'] as $forbidden) unified_assert(!str_contains($module,$forbidden)&&!str_contains($js,$forbidden)&&!str_contains($migration,$forbidden),'out-of-scope autonomous-worker architecture token found: '.$forbidden);
unified_assert(str_contains($module,'portal_render_document_version_panel')&&str_contains($module,'圖片版本與還原'),'IMAGE REVIEW must expose direct publication history and restore without a candidate decision gate');
echo "[OK] unified IMAGE REVIEW collaboration source/UI contract passed.\n";
