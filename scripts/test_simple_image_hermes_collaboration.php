<?php
declare(strict_types=1);
function simple_image_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/index.php');
$bootstrap=(string)file_get_contents($root.'/app/bootstrap.php');
$module=(string)file_get_contents($root.'/app/image_library.php');
$js=(string)file_get_contents($root.'/assets/image-library.js');
$css=(string)file_get_contents($root.'/assets/app.css');

simple_image_assert(str_contains($index,'$imageCollaborationActions')&&str_contains($index,"'visual_assets','image_review','image_annotations_save','image_annotation_status','image_export','image_requirement_revision_create','image_requirement_revision_status','image_requirement_revision_archive'")&&str_contains($index,'portal_require_admin($database)'),'all image collaboration routes except private image streaming must be administrator-only server-side');
$adminNav=strpos($bootstrap,'if (portal_is_admin($user))');$visualNav=strpos($bootstrap,"portal_url('visual_assets')",$adminNav===false?0:$adminNav);
simple_image_assert($adminNav!==false&&$visualNav!==false&&$visualNav>$adminNav&&$visualNav-$adminNav<240,'visual asset navigation must only be rendered for administrators');
simple_image_assert(str_contains($module,'data-image-revision-copy disabled')&&str_contains($js,"copyHermesPrompt.textContent=copyReady?'複製完整修改指令':'請先整理精確規格'"),'IMAGE REVIEW stored-revision copy action must render fail-closed and provide one-click AI prompt copy');
simple_image_assert(str_contains($module,'data-image-revision-download disabled')&&str_contains($module,'下載修改需求 JSON'),'IMAGE REVIEW stored-revision JSON action must render fail-closed and provide one-click download');
simple_image_assert(str_contains($module,'data-image-revision-details')&&str_contains($module,'<details'),'technical prompt preview must be in a default-closed disclosure');
simple_image_assert(!str_contains($module,'<details open')&&!str_contains($module,'<details class="image-requirement-details" open'),'technical disclosure must start closed');
simple_image_assert(str_contains($module,'data-document-title=')&&str_contains($module,'data-relative-path=')&&str_contains($module,'data-source-hash='),'export must be bound to title, relative path, and immutable source hash');
simple_image_assert(str_contains($js,'window.TWImageRequirementBuilder')&&str_contains($js,"schemaVersion:'twwater-image-change-request-v2'")&&str_contains($js,'sourceHash')&&str_contains($js,'relativePath'),'shared executable JSON builder must preserve source identity');
simple_image_assert(str_contains($js,'尚有未儲存變更')&&str_contains($js,'hasUnsavedChanges'),'copy/download must fail closed while local annotations differ from saved state');
simple_image_assert(str_contains($js,'navigator.clipboard.writeText')&&str_contains($js,'URL.createObjectURL')&&str_contains($js,"application/json"),'copy and local JSON download must be browser-only without an AI backend');
simple_image_assert(str_contains($js,'先封存目前正式原圖')&&str_contains($js,'原子替換同一路徑')&&str_contains($js,'定位資料衝突時停止並回報'),'Hermes prompt must require archive-before-replace and stop on identity conflict');
simple_image_assert(str_contains($js,'annotations.filter((item)=>item.saved')||str_contains($js,'annotations.filter((item) => item.saved'),'export must use saved annotations only');
simple_image_assert(str_contains($css,'.image-requirement-actions')&&str_contains($css,'.image-requirement-details'),'new controls must have responsive styling');
simple_image_assert(!str_contains($index,'image_candidate')&&!str_contains($module,'image_candidate')&&!str_contains($js,'image_candidate'),'simple flow must not reintroduce candidate/worker routes');
fwrite(STDOUT,"[OK] simple IMAGE REVIEW to Hermes collaboration contract passed.\n");
