<?php
declare(strict_types=1);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function operation_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$catalog = portal_document_version_operation_status_catalog();
foreach (['queued','running','conflict','local-restored','indexed','preview-ready','failed'] as $status) {
    operation_test_assert(isset($catalog[$status]), 'Missing operation status: ' . $status);
}
operation_test_assert(($catalog['conflict']['terminal'] ?? false) === true, 'Conflict must be terminal.');
operation_test_assert(($catalog['indexed']['terminal'] ?? true) === false, 'Indexed must wait for preview readiness.');

$currentHash = str_repeat('a', 64);
$targetHash = str_repeat('b', 64);
$document = [
    'document_id' => 42,
    'public_id' => '8D22046C-ECE0-4818-A67E-12799B05AE25',
    'title' => '供水監測文件即時瀏覽平台建議方案',
    'relative_path' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'file_name' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'extension' => 'pptx',
    'status_code' => 'published',
    'content_hash' => $currentHash,
    'file_size_bytes' => 44461,
    'source_modified_at' => '2026-08-25 14:00:00',
];
$target = [
    'is_current' => false,
    'content_hash' => $targetHash,
    'version_number' => 1,
    'file_size_bytes' => 283648,
    'source_modified_at' => '2026-08-18 07:03:22',
    'archived_utc' => '2026-08-25 14:03:02',
    'archived_reason' => 'publish',
    'artifact_path' => 'C:/TWWATER/document-versions/source.pptx',
];
$comparison = portal_render_document_version_compare($document, $target, ['role_code' => 'admin'], null);
operation_test_assert(str_contains($comparison, $currentHash), 'Comparison must show the full current hash.');
operation_test_assert(str_contains($comparison, $targetHash), 'Comparison must show the full target hash.');
operation_test_assert(str_contains($comparison, '目前版本'), 'Comparison current column is missing.');
operation_test_assert(str_contains($comparison, '目標版本'), 'Comparison target column is missing.');
operation_test_assert(str_contains($comparison, '發布狀態影響'), 'Publish-state impact is missing.');
operation_test_assert(str_contains($comparison, '維持已發布'), 'Published-state impact must be explicit.');
operation_test_assert(str_contains($comparison, 'Google Drive'), 'Drive synchronization warning is missing.');
operation_test_assert(str_contains($comparison, 'document_version_restore'), 'Comparison confirmation form is missing.');

$activeComparison = portal_render_document_version_compare($document, $target, ['role_code' => 'admin'], [
    'operation_id' => '11111111-1111-4111-8111-111111111111',
    'status_code' => 'running',
]);
operation_test_assert(!str_contains($activeComparison, 'type="submit"'), 'Active operation must disable duplicate restore submission.');
operation_test_assert(str_contains($activeComparison, '已有進行中的版本操作'), 'Duplicate-operation warning is missing.');

$operation = [
    'operation_id' => '11111111-1111-4111-8111-111111111111',
    'status_code' => 'indexed',
    'expected_current_hash' => $currentHash,
    'target_content_hash' => $targetHash,
    'previous_content_hash' => $currentHash,
    'actual_content_hash' => $targetHash,
    'drive_sync_status' => 'pending-confirmation',
    'requested_at' => '2026-08-25 14:00:00',
    'started_at' => '2026-08-25 14:00:02',
    'local_restored_at' => '2026-08-25 14:00:05',
    'indexed_at' => '2026-08-25 14:00:08',
    'preview_ready_at' => null,
    'completed_at' => null,
    'error_code' => null,
];
$events = [
    ['status_code' => 'queued', 'event_code' => 'REQUEST_QUEUED', 'occurred_at' => '2026-08-25 14:00:00'],
    ['status_code' => 'running', 'event_code' => 'BRIDGE_CLAIMED', 'occurred_at' => '2026-08-25 14:00:02'],
    ['status_code' => 'indexed', 'event_code' => 'PORTAL_INDEXED', 'occurred_at' => '2026-08-25 14:00:08'],
];
$page = portal_render_document_version_operation_page($document, $operation, $events);
operation_test_assert(str_contains($page, 'indexed'), 'Operation page current state is missing.');
operation_test_assert(str_contains($page, 'preview-ready'), 'Operation timeline must show the preview-ready stage.');
operation_test_assert(str_contains($page, 'pending-confirmation'), 'Drive pending-confirmation state is missing.');
operation_test_assert(str_contains($page, 'data-operation-status-url'), 'Operation page must expose a safe polling endpoint.');

$migration = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '008_document_version_operations.sql';
operation_test_assert(is_file($migration), 'Operation tracking migration is missing.');
$migrationSource = (string) file_get_contents($migration);
foreach (['document_version_operations','document_version_operation_events','UX_document_version_operations_active','preview-ready','conflict'] as $needle) {
    operation_test_assert(str_contains($migrationSource, $needle), 'Migration contract missing: ' . $needle);
}
$indexSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
foreach (["'document_version_compare'", "'document_version_operation'", "'document_version_operation_status'"] as $route) {
    operation_test_assert(str_contains($indexSource, $route), 'Missing operation route: ' . $route);
}
$operationSource=(string)file_get_contents(dirname(__DIR__).'/app/document_version_operations.php');
$createStart=strpos($operationSource,'function portal_create_document_version_restore_operation');
$createEnd=$createStart===false?false:strpos($operationSource,'function portal_get_document_version_operation',$createStart);
$create=$createStart===false||$createEnd===false?'':substr($operationSource,$createStart,$createEnd-$createStart);
$queuePos=strpos($create,'portal_queue_document_version_restore');
$commitPos=$queuePos===false?false:strrpos(substr($create,0,$queuePos),'$database->commit();');
$reservedPos=strpos($create,"'REQUEST_RESERVED'");
$queuedEventPos=$queuePos===false?false:strpos($create,"'REQUEST_QUEUED'",$queuePos);
operation_test_assert($commitPos!==false&&$queuePos!==false&&$reservedPos!==false&&$reservedPos<$commitPos&&$commitPos<$queuePos&&$queuedEventPos!==false&&$queuePos<$queuedEventPos,'Durable SQL intent/event ordering must be reserved -> commit -> queue publish -> queued event.');
operation_test_assert(str_contains($operationSource,'portal_reconcile_document_version_operation_outbox'),'Durable queue outbox reconciler is missing.');
operation_test_assert(str_contains($operationSource,'portal_document_version_config_for_document($config, $document)'),'Outbox reconciliation must select image/document scope per catalog row.');
operation_test_assert(str_contains($operationSource,'REQUEST_RESERVED')&&str_contains($operationSource,'REQUEST_QUEUED'),'Reserved and published outbox events must be distinct.');

$jsSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.js');
operation_test_assert(str_contains($jsSource, 'data-operation-status-url'), 'Operation status polling script is missing.');
$cssSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.css');
foreach (['.version-compare-grid', '.operation-timeline', '.operation-status', '.drive-sync-warning', '.operation-history'] as $selector) {
    operation_test_assert(str_contains($cssSource, $selector), 'Operation UI style is missing: ' . $selector);
}

echo "[OK] Document version operation UX and state contracts passed.\n";
