<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function version_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'twwater-version-test-' . bin2hex(random_bytes(6));
$publicId = '8d22046c-ece0-4818-a67e-12799b05ae25';
$oldHash = hash('sha256', 'old-version');
$currentHash = str_repeat('b', 64);
$archiveDir = $root . DIRECTORY_SEPARATOR . $publicId . DIRECTORY_SEPARATOR . $oldHash;
if (!mkdir($archiveDir, 0700, true) && !is_dir($archiveDir)) {
    throw new RuntimeException('Unable to create fixture directory.');
}
file_put_contents($archiveDir . DIRECTORY_SEPARATOR . 'source.pptx', 'old-version');
$manifest = [
    'schema_version' => 1,
    'document_public_id' => $publicId,
    'document_relative_path' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'original_file_name' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'extension' => 'pptx',
    'content_hash' => $oldHash,
    'file_size_bytes' => strlen('old-version'),
    'source_modified_utc' => '2026-08-25T10:00:00Z',
    'archived_utc' => '2026-08-25T11:00:00Z',
    'archived_reason' => 'publish',
    'artifact_file' => 'source.pptx',
];
file_put_contents(
    $archiveDir . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);

$config = ['document_version_root' => $root, 'document_version_enabled' => true];
$document = [
    'public_id' => strtoupper($publicId),
    'relative_path' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'file_name' => '供水監測文件即時瀏覽平台建議方案.pptx',
    'extension' => 'pptx',
    'content_hash' => $currentHash,
    'file_size_bytes' => 1234,
    'source_modified_at' => '2026-08-25 12:00:00',
];

try {
    $versions = portal_list_document_versions($config, $document, [
        strtolower($oldHash) => 1,
        strtolower($currentHash) => 2,
    ]);
    version_test_assert(count($versions) === 2, 'Expected current and archived versions.');
    version_test_assert($versions[0]['is_current'] === true, 'Current version must be first.');
    version_test_assert($versions[0]['version_number'] === 2, 'Current version number mismatch.');
    version_test_assert($versions[1]['is_current'] === false, 'Archived version must not be current.');
    version_test_assert($versions[1]['content_hash'] === $oldHash, 'Archived hash mismatch.');
    version_test_assert($versions[1]['version_number'] === 1, 'Archived version number mismatch.');
    version_test_assert(is_file((string) $versions[1]['artifact_path']), 'Archived artifact must resolve to a real file.');

    $sessionRoot = $root . DIRECTORY_SEPARATOR . 'sessions';
    mkdir($sessionRoot, 0700, true);
    $config['session_save_path'] = $sessionRoot;
    $operationId = portal_queue_document_version_restore($config, $document, $oldHash, ['user_id' => 7]);
    version_test_assert(portal_valid_public_id($operationId) !== '', 'Restore operation id must be a UUID.');
    $pending = glob($sessionRoot . DIRECTORY_SEPARATOR . 'document-version-requests' . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . '*.json');
    version_test_assert(is_array($pending) && count($pending) === 1, 'Exactly one restore request must be queued.');
    $request = json_decode((string) file_get_contents($pending[0]), true, 8, JSON_THROW_ON_ERROR);
    version_test_assert($request['operation'] === 'restore', 'Restore request operation mismatch.');
    version_test_assert($request['document_public_id'] === $publicId, 'Restore request document id mismatch.');
    version_test_assert($request['target_content_hash'] === $oldHash, 'Restore request target hash mismatch.');
    version_test_assert($request['expected_current_hash'] === $currentHash, 'Restore request stale-write guard mismatch.');
    version_test_assert(!array_key_exists('artifact_path', $request), 'Restore request must not accept an arbitrary artifact path.');
    version_test_assert((int) $request['requested_by_user_id'] === 7, 'Restore request actor mismatch.');

    $approvalRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-approved';
    mkdir($approvalRoot, 0700, true);
    $approvalPath = $approvalRoot . DIRECTORY_SEPARATOR . $publicId . '-' . $currentHash . '.json';
    file_put_contents($approvalPath, json_encode([
        'schema_version' => 1,
        'operation_id' => portal_document_version_uuid(),
        'document_public_id' => $publicId,
        'document_relative_path' => '供水監測文件即時瀏覽平台建議方案.pptx',
        'approved_content_hash' => $currentHash,
        'approved_utc' => '2026-08-25T12:00:00Z',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $approvals = portal_read_document_version_approvals($config);
    $approvalKey = strtolower('供水監測文件即時瀏覽平台建議方案.pptx');
    version_test_assert(isset($approvals[$approvalKey]), 'Controlled version approval must be discovered.');
    version_test_assert(portal_document_version_approval_matches($approvals[$approvalKey], $document, $currentHash), 'Controlled version approval must match document id/path/hash.');
    portal_consume_document_version_approvals([$approvals[$approvalKey]]);
    version_test_assert(!is_file($approvalPath), 'Consumed controlled version approval must be removed.');

    putenv('PORTAL_DOCUMENT_VERSION_ROOT=' . $root);
    $runtimeConfig = portal_config(false);
    version_test_assert(($runtimeConfig['document_version_enabled'] ?? false) === true, 'Version root environment must enable version management.');
    version_test_assert(realpath((string) $runtimeConfig['document_version_root']) === realpath($root), 'Configured version root mismatch.');
    putenv('PORTAL_DOCUMENT_VERSION_ROOT');

    $panel = portal_render_document_version_panel($config, $document, ['role_code' => 'admin'], [
        strtolower($oldHash) => 1,
        strtolower($currentHash) => 2,
    ], [[
        'operation_id' => '11111111-1111-4111-8111-111111111111',
        'status_code' => 'preview-ready',
        'target_content_hash' => $oldHash,
        'drive_sync_status' => 'pending-confirmation',
        'requested_at' => '2026-08-25 12:30:00',
        'completed_at' => '2026-08-25 12:31:00',
        'error_code' => null,
    ]]);
    version_test_assert(str_contains($panel, '版本管理'), 'Version panel heading is missing.');
    version_test_assert(str_contains($panel, '目前版本'), 'Version panel current marker is missing.');
    version_test_assert(str_contains($panel, 'action=document_version_file'), 'Archived version online file route is missing.');
    version_test_assert(str_contains($panel, 'action=document_version_compare'), 'Archived version comparison route is missing.');
    version_test_assert(!str_contains($panel, 'action=document_version_restore'), 'Restore must not submit before the comparison page.');
    version_test_assert(str_contains($panel, '操作結果及錯誤紀錄'), 'Operation history section is missing.');
    version_test_assert(str_contains($panel, 'action=document_version_operation'), 'Operation result link is missing.');
    version_test_assert(str_contains($panel, substr($oldHash, 0, 12)), 'Archived version hash summary is missing.');

    $indexSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
    version_test_assert(str_contains($indexSource, "'document_version_file'"), 'Version file route is not registered.');
    version_test_assert(str_contains($indexSource, "'document_version_restore'"), 'Version restore route is not registered.');
    version_test_assert(str_contains($indexSource, 'portal_render_document_version_panel'), 'Document page does not render version history.');
    version_test_assert(str_contains($indexSource, 'portal_handle_document_version_restore'), 'Version restore handler is not wired.');
    $cssSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.css');
    version_test_assert(str_contains($cssSource, '.version-panel'), 'Version panel styles are missing.');
    version_test_assert(str_contains($cssSource, '.version-actions'), 'Version action styles are missing.');
    $bootstrapSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php');
    version_test_assert(str_contains($bootstrapSource, 'assets/app.js'), 'Global confirmation script is not loaded.');

    try {
        portal_queue_document_version_restore($config, $document, $currentHash, ['user_id' => 7]);
        throw new RuntimeException('Restoring the current version must be rejected.');
    } catch (RuntimeException $exception) {
        version_test_assert(str_contains($exception->getMessage(), '目前版本'), 'Unexpected same-version rejection.');
    }

    echo "[OK] Document version listing and restore queue contracts passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    @rmdir($root);
}
