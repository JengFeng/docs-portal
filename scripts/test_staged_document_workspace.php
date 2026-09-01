<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'staging.php';

function staging_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function staging_test_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

foreach (['pptx','pdf','docx','xlsx','md','txt'] as $extension) {
    staging_assert(portal_staging_extension_allowed($extension), 'Expected supported extension: ' . $extension);
}
foreach (['exe','html','php','gdoc',''] as $extension) {
    staging_assert(!portal_staging_extension_allowed($extension), 'Unexpected supported extension: ' . $extension);
}
staging_assert(portal_staging_file_name('供水監測 設計稿 V2.pptx') === '供水監測 設計稿 V2.pptx', 'Chinese filename must be preserved.');
foreach (['../a.pdf', 'folder/a.pdf', 'folder\\a.pdf', '~$draft.pptx', '.hidden.pdf', 'a.exe'] as $invalid) {
    try {
        portal_staging_file_name($invalid);
        throw new RuntimeException('Invalid filename was accepted: ' . $invalid);
    } catch (RuntimeException $exception) {
        staging_assert($exception->getMessage() === '暫存文件檔名或格式不正確。', 'Invalid filename must fail safely.');
    }
}
staging_assert(portal_staging_upload_size(1) === 1, 'One-byte file must be accepted.');
staging_assert(portal_staging_upload_size(524288000) === 524288000, '500 MiB file must be accepted.');
foreach ([0, 524288001] as $invalidSize) {
    try {
        portal_staging_upload_size($invalidSize);
        throw new RuntimeException('Invalid size was accepted.');
    } catch (RuntimeException $exception) {
        staging_assert($exception->getMessage() === '暫存文件大小必須介於 1 byte 到 500 MB。', 'Invalid size must fail safely.');
    }
}
staging_assert(portal_staging_expected_chunks(524288000, 1048576) === 500, '500 MiB must use 500 one-MiB chunks.');
staging_assert(portal_staging_target_relative_path('文件.pdf') === '網站文件/文件.pdf', 'New files must target the Website Documents subtree.');
staging_assert(portal_staging_normalize_relative_path('網站文件\\規格\\文件.pdf') === '網站文件/規格/文件.pdf', 'Relative path must normalize separators.');
foreach (['../文件.pdf', '/網站文件/文件.pdf', 'C:/文件.pdf', '網站文件/../文件.pdf'] as $invalidPath) {
    try {
        portal_staging_normalize_relative_path($invalidPath);
        throw new RuntimeException('Invalid relative path was accepted.');
    } catch (RuntimeException $exception) {
        staging_assert($exception->getMessage() === '正式文件路徑不正確。', 'Invalid relative path must fail safely.');
    }
}

$index = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
$bridge = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'document_bridge.ps1');
$appJs = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.js');
$presentation = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'presentation.php');
$presentationJs = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'presentation.js');
foreach (['staging_workspace', 'staging_chunk', 'staging_finalize', 'staging_preview_asset', 'staging_confirm', 'staging_return'] as $action) {
    staging_assert(str_contains($index, "'{$action}'"), 'Missing staging route: ' . $action);
}
$stagingSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'staging.php');
staging_assert(str_contains($stagingSource, "relative_path LIKE N'網站文件/%'"), 'Only formal Website Documents targets may be selected for replacement.');
staging_assert(str_contains($stagingSource, 'function portal_staging_cleanup_expired'), 'Expired private workspaces need bounded cleanup.');
staging_assert(str_contains($stagingSource, 'function portal_staging_prepare_commit_request'), 'Commit requests must remain hidden from the bridge until the database transition commits.');
staging_assert(str_contains($stagingSource, "['preview_ready','changes_requested','confirming','confirmed','conflict']"), 'Reviewed previews must remain privately visible through terminal states.');
staging_assert(str_contains($stagingSource, "\$config['staged_commit_root']"), 'Bridge results must use a directional channel outside the AppPool session directory.');
staging_assert(str_contains($stagingSource, '$artifactWasCreated'), 'Failed finalization must remove partial assembled artifacts so upload can retry.');
$database = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '010_staged_document_revisions.sql');
foreach (['dbo.staged_document_revisions', 'created_by_user_id', 'confirmed_by_user_id', 'change_request_id', 'review_note', 'expected_source_hash', 'staged_content_hash'] as $needle) {
    staging_assert(str_contains($database, $needle), 'Missing staging schema contract: ' . $needle);
}
staging_assert(str_contains($bridge, 'staged_commit_engine.ps1'), 'Bridge must load the staged commit engine.');
staging_assert(str_contains($bridge, 'Invoke-TWWaterStagedCommitRequests'), 'Bridge must process staged commit requests.');
staging_assert(str_contains($appJs, '[data-staging-refresh]'), 'Confirming workspaces must poll for bridge results.');
staging_assert(!str_contains($presentation, "'staging_url'"), 'Change requests must not link to the retired private staging workspace.');
staging_assert(!str_contains($presentationJs, 'open-staging') && str_contains($presentationJs, 'Google Drive'), 'Review UI must direct completed revisions to the unified Google Drive upload flow.');

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'twwater-staging-contract-' . bin2hex(random_bytes(6));
$commitRoot = $tempRoot . DIRECTORY_SEPARATOR . 'commit-channel';
$stagingRoot = $tempRoot . DIRECTORY_SEPARATOR . 'staging';
$id = '44444444-4444-4444-8444-444444444444';
mkdir($commitRoot . DIRECTORY_SEPARATOR . 'requests', 0770, true);
mkdir($commitRoot . DIRECTORY_SEPARATOR . 'results', 0770, true);
mkdir($stagingRoot . DIRECTORY_SEPARATOR . $id, 0770, true);
file_put_contents($stagingRoot . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'source.txt', 'candidate');
$config = ['staged_commit_root' => $commitRoot, 'preupload_preview_root' => $stagingRoot];
$workspace = [
    'public_id' => $id,
    'extension' => 'txt',
    'target_relative_path' => '網站文件/test.txt',
    'staged_content_hash' => hash('sha256', 'candidate'),
    'expected_source_hash' => null,
];
$preparedRequest = portal_staging_prepare_commit_request($config, $workspace);
staging_assert(is_file((string) $preparedRequest['pending_path']), 'Prepared request must exist privately.');
staging_assert(!is_file((string) $preparedRequest['final_path']), 'Bridge-visible request must not exist before publish.');
$requestPath = portal_staging_publish_commit_request($preparedRequest);
staging_assert(is_file($requestPath), 'Confirm must create a bounded bridge request.');
$request = json_decode((string) file_get_contents($requestPath), true, 8, JSON_THROW_ON_ERROR);
staging_assert(($request['operation_id'] ?? '') === $id, 'Commit request must bind the workspace id.');
staging_assert(($request['candidate_sha256'] ?? '') === hash('sha256', 'candidate'), 'Commit request must bind the candidate hash.');
$resultPath = $commitRoot . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . $id . '.json';
file_put_contents($resultPath, json_encode(['schema_version' => 1, 'operation_id' => $id, 'status' => 'local-drive-written', 'actual_sha256' => hash('sha256', 'candidate'), 'candidate_sha256' => hash('sha256', 'candidate'), 'target_relative_path' => '網站文件/test.txt', 'expected_current_sha256' => null, 'error_code' => null]));
$result = portal_staging_read_commit_result($config, $id);
staging_assert(($result['status'] ?? '') === 'local-drive-written', 'Valid bridge result must be readable.');
file_put_contents($resultPath, json_encode(['schema_version' => 1, 'operation_id' => '55555555-5555-4555-8555-555555555555', 'status' => 'local-drive-written']));
staging_assert(portal_staging_read_commit_result($config, $id) === null, 'Mismatched bridge results must be rejected.');
staging_test_remove_tree($tempRoot);

echo "[OK] Staged document workspace contracts passed.\n";
