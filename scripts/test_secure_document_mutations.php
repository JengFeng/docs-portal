<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
function mutation_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = dirname(__DIR__);
mutation_assert(function_exists('portal_validate_document_replacement_upload'), 'replacement validation API missing');
mutation_assert(function_exists('portal_stage_replacement_candidate'), 'same-directory candidate staging API missing');
mutation_assert(function_exists('portal_run_atomic_document_replace'), 'fixed helper invocation API missing');
mutation_assert(function_exists('portal_signal_document_bridge'), 'durable bridge signal API missing');
mutation_assert(function_exists('portal_reconcile_document_mutations'), 'bounded active-operation recovery API missing');
mutation_assert(function_exists('portal_render_document_mutation_actions'), 'per-row actions renderer missing');
$document=['public_id'=>'11111111-1111-4111-8111-111111111111','relative_path'=>'folder/report.pdf','file_name'=>'report.pdf','extension'=>'pdf','content_hash'=>str_repeat('a',64),'status_code'=>'published'];
$user=['user_id'=>7,'role_code'=>'reader'];
$actions=portal_render_document_mutation_actions($document,$user);
mutation_assert($actions==='', 'dormant request must not render mutation actions');
$mutationSource=(string)file_get_contents($root.'/app/document_mutations.php');
foreach(['檢視','編輯','封存','document_replace','document_archive','expected_hash'] as $needle) mutation_assert(str_contains($mutationSource,$needle), 'row action contract missing: '.$needle);
$bad=false;try{portal_validate_document_replacement_upload($document,['name'=>'renamed.pdf','tmp_name'=>'x','size'=>1,'error'=>UPLOAD_ERR_OK],false);}catch(Throwable){$bad=true;}
mutation_assert($bad,'replacement must preserve exact name');
$bad=false;try{portal_validate_document_replacement_upload($document,['name'=>'report.exe','tmp_name'=>'x','size'=>1,'error'=>UPLOAD_ERR_OK],false);}catch(Throwable){$bad=true;}
mutation_assert($bad,'replacement must preserve extension');
$bad=false;try{portal_validate_document_replacement_upload($document,['name'=>'report.pdf','tmp_name'=>'x','size'=>524288001,'error'=>UPLOAD_ERR_OK],false);}catch(Throwable){$bad=true;}
mutation_assert($bad,'replacement above 500 MiB must fail');
$valid=portal_validate_document_replacement_upload($document,['name'=>'report.pdf','tmp_name'=>'x','size'=>524288000,'error'=>UPLOAD_ERR_OK],false);
mutation_assert($valid['size']===524288000 && $valid['extension']==='pdf','exact 500 MiB metadata must validate');
$index=(string)file_get_contents($root.'/index.php');
foreach(["'document_replace'","'document_replace_submit'","'document_archive'"] as $route) mutation_assert(str_contains($index,$route),'route missing: '.$route);
$migration=(string)file_get_contents($root.'/database/029_secure_document_mutations.sql');
foreach(['document_mutation_operations','UX_document_mutation_operations_active','portal_prepare_document_replacement','portal_mark_document_replaced','portal_complete_document_replacement','portal_soft_archive_document','portal_restore_archived_document','DENY INSERT,UPDATE,DELETE','GRANT EXECUTE'] as $needle) mutation_assert(str_contains($migration,$needle),'migration contract missing: '.$needle);
$helper=(string)file_get_contents($root.'/scripts/atomic-replace-document.ps1');
foreach(['C:\\web\\gary\\TWWATER\\document-library','File]::Replace','ReparsePoint','expected','candidate','ConvertTo-Json','GetFinalPathNameByHandle'] as $needle) mutation_assert(str_contains($helper,$needle),'atomic helper contract missing: '.$needle);
mutation_assert(!str_contains($helper,'param([string]$Root'), 'helper root must not be caller-controlled');
$source=(string)file_get_contents($root.'/app/document_mutations.php');
foreach(['is_uploaded_file','hash_file','proc_open','bypass_shell','portal_prepare_document_replacement','portal_mark_document_replaced','portal_sync_documents','portal_complete_document_replacement'] as $needle) mutation_assert(str_contains($source,$needle),'handler sequencing/security missing: '.$needle);
$css=(string)file_get_contents($root.'/assets/app.css');
foreach(['.document-row-actions','.document-replace-form','@media'] as $needle) mutation_assert(str_contains($css,$needle),'RWD mutation style missing: '.$needle);
echo "[OK] Secure document replacement/archive contracts passed.\n";
