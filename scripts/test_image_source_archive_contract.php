<?php
declare(strict_types=1);
function portal_valid_public_id(string $value): string { return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',$value)===1?strtolower($value):''; }
function portal_is_admin(array $user): bool { return ($user['role_code']??'')==='admin'; }
function portal_e(string $value): string { return htmlspecialchars($value,ENT_QUOTES,'UTF-8'); }
function portal_format_file_size(int $value): string { return (string)$value; }
function portal_format_datetime(string $value): string { return $value; }
function portal_url(string $action,array $params=[]): string { return '?action='.$action; }
function portal_csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
require_once __DIR__ . '/../app/document_versions.php';
function check(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"[FAIL] $message\n");exit(1);} }
check(function_exists('portal_document_version_config_for_document'),'image-aware version config selector is missing');
$base=['document_version_root'=>'C:/general','document_version_enabled'=>true,'document_restore_enabled'=>true,'image_source_archive_root'=>'C:/images','image_source_archive_enabled'=>true,'image_command_enabled'=>true];
$image=portal_document_version_config_for_document($base,['extension'=>'png']);
check($image['document_version_root']==='C:/images','image must use the private image archive root');
check($image['document_version_scope']==='image-source','image version scope must be image-source');
check($image['document_restore_enabled']===true,'image restore must enable only after the signed command contract is configured');
$gif=portal_document_version_config_for_document($base,['extension'=>'gif']);
check($gif['document_version_enabled']===true&&$gif['document_restore_enabled']===false,'GIF version history may remain readable but GIF restore must stay disabled');
$webp=portal_document_version_config_for_document($base,['extension'=>'webp']);
check($webp['document_version_enabled']===true&&$webp['document_restore_enabled']===false,'WEBP history may remain readable but restore must stay disabled until a production decoder is verified');
$document=portal_document_version_config_for_document($base,['extension'=>'pdf']);
check($document['document_version_root']==='C:/general','non-image document must retain the general archive root');
check($document['document_version_scope']==='document','non-image scope must remain document');
$compare=portal_render_document_version_compare(
    ['public_id'=>'4380862e-aca7-4637-872e-0222ac65d09b','content_hash'=>str_repeat('b',64),'file_size_bytes'=>20,'source_modified_at'=>'2026-08-30','status_code'=>'draft'],
    ['content_hash'=>str_repeat('a',64),'is_current'=>false,'file_size_bytes'=>10,'source_modified_at'=>'2026-08-29','archived_utc'=>'2026-08-30','version_number'=>1],
    ['role_code'=>'admin'],null,true,'image-source'
);
check(str_contains($compare,'網站正式圖片還原')&&!str_contains($compare,'Google Drive 同步提示'),'enabled image restore comparison must explain the website-only signed restore flow');
check(str_contains($compare,'action=document_version_restore'),'enabled image restore comparison must render the admin POST action');
$bootstrap=file_get_contents(__DIR__.'/../app/bootstrap.php');$imageLibrary=file_get_contents(__DIR__.'/../app/image_library.php');$index=file_get_contents(__DIR__.'/../index.php');
check(str_contains($bootstrap,"PORTAL_IMAGE_SOURCE_ARCHIVE_ROOT"),'bootstrap must load the image archive root');
check(str_contains($bootstrap,"PORTAL_IMAGE_COMMAND_KEY_PATH"),'bootstrap must load the image command key path');
check(str_contains(file_get_contents(__DIR__.'/../app/document_versions.php'),'request_hmac'),'Portal restore queue must sign image requests');
check(substr_count($index,'portal_document_version_config_for_document')>=4,'all version file/compare/restore/view handlers must select the image archive root');
check(str_contains($imageLibrary,'portal_render_document_version_panel'),'IMAGE REVIEW must render image version history');
check(!str_contains($imageLibrary,'核准此候選'),'IMAGE REVIEW must remove candidate approval UI');
check(str_contains($imageLibrary,'AI 修改完成後會直接顯示目前正式圖片'),'IMAGE REVIEW must explain direct publication');
check(!str_contains($imageLibrary,'sourceReadOnlyUntilAdminConfirmation'),'AI requirement payload must not retain the superseded approval gate');
check(str_contains($imageLibrary,'archiveBeforeAutomaticReplace'),'AI requirement payload must require archive before automatic replacement');
check(!preg_match("/'image_candidate_(?:asset|decision)'/",$index),'candidate routes must be retired from the front controller');
echo "[OK] Image source archive website contract passed.\n";
