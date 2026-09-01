<?php
declare(strict_types=1);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
function lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$config = portal_config();
$database = portal_open_database($config);
$fixture = $database->query(
    "SELECT TOP (1) d.document_id, d.status_code, LOWER(d.content_hash) AS content_hash,
            (SELECT TOP (1) user_id FROM dbo.auth_users WHERE role_code = 'admin' AND is_active = 1 ORDER BY user_id) AS user_id
     FROM dbo.documents d
     WHERE d.extension = 'pptx' AND d.content_hash IS NOT NULL
       AND EXISTS
       (
           SELECT 1 FROM dbo.document_versions dv
           INNER JOIN dbo.presentation_renditions r ON r.document_version_id = dv.version_id
           WHERE dv.document_id = d.document_id AND dv.source_content_hash = d.content_hash
             AND r.status_code = 'ready' AND r.is_current = 1
       )
     ORDER BY d.document_id"
)->fetch();
lifecycle_assert($fixture !== false, 'No ready PPTX fixture is available.');
$sessionRoot = realpath((string) $config['session_save_path']);
lifecycle_assert($sessionRoot !== false, 'Session root is unavailable.');
$completedRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-requests' . DIRECTORY_SEPARATOR . 'completed';
$failedRoot = $sessionRoot . DIRECTORY_SEPARATOR . 'document-version-requests' . DIRECTORY_SEPARATOR . 'failed';
foreach ([$completedRoot, $failedRoot] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create lifecycle result directory.');
    }
}
$target = strtolower((string) $fixture['content_hash']);
$expected = hash('sha256', 'lifecycle-expected-old-version');
if ($expected === $target) { $expected = str_repeat('f', 64); }
$files = [];
$database->beginTransaction();
try {
    $insert = $database->prepare(
        "INSERT INTO dbo.document_version_operations
            (operation_id, document_id, operation_type, status_code, source_status_code,
             expected_current_hash, target_content_hash, drive_sync_status, requested_by_user_id)
         VALUES (?, ?, 'restore', 'queued', ?, ?, ?, 'pending-confirmation', ?)"
    );
    $readyId = portal_document_version_uuid();
    $insert->execute([$readyId, (int) $fixture['document_id'], (string) $fixture['status_code'], $expected, $target, (int) $fixture['user_id']]);
    portal_insert_document_version_operation_event($database, $readyId, 'queued', 'REQUEST_QUEUED', 'portal', 'Lifecycle fixture.', (int) $fixture['user_id']);
    $completedPath = $completedRoot . DIRECTORY_SEPARATOR . $readyId . '.json';
    file_put_contents($completedPath, json_encode([
        'schema_version' => 1,
        'operation_id' => $readyId,
        'status' => 'local-restored',
        'operation' => 'restore',
        'previous_hash' => $expected,
        'current_hash' => $target,
        'completed_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $files[] = $completedPath;
    $ready = portal_refresh_document_version_operation($database, $config, $readyId);
    lifecycle_assert(($ready['status_code'] ?? '') === 'preview-ready', 'PPTX operation did not advance to preview-ready.');
    lifecycle_assert(($ready['drive_sync_status'] ?? '') === 'pending-confirmation', 'Drive state must remain pending-confirmation.');
    $events = array_column(portal_get_document_version_operation_events($database, $readyId), 'event_code');
    foreach (['REQUEST_QUEUED','LOCAL_SOURCE_RESTORED','PORTAL_INDEXED','PREVIEW_READY'] as $eventCode) {
        lifecycle_assert(in_array($eventCode, $events, true), 'Lifecycle event missing: ' . $eventCode);
    }

    $conflictId = portal_document_version_uuid();
    $insert->execute([$conflictId, (int) $fixture['document_id'], (string) $fixture['status_code'], $expected, $target, (int) $fixture['user_id']]);
    portal_insert_document_version_operation_event($database, $conflictId, 'queued', 'REQUEST_QUEUED', 'portal', 'Conflict fixture.', (int) $fixture['user_id']);
    $failedPath = $failedRoot . DIRECTORY_SEPARATOR . $conflictId . '.json';
    file_put_contents($failedPath, json_encode([
        'schema_version' => 1,
        'operation_id' => $conflictId,
        'status' => 'conflict',
        'operation' => 'restore',
        'expected_current_hash' => $expected,
        'target_content_hash' => $target,
        'error_code' => 'CURRENT_VERSION_CONFLICT',
        'completed_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $files[] = $failedPath;
    $conflict = portal_refresh_document_version_operation($database, $config, $conflictId);
    lifecycle_assert(($conflict['status_code'] ?? '') === 'conflict', 'Conflict result was not imported.');
    lifecycle_assert(($conflict['error_code'] ?? '') === 'CURRENT_VERSION_CONFLICT', 'Conflict error code mismatch.');
} finally {
    if ($database->inTransaction()) { $database->rollBack(); }
    foreach ($files as $file) {
        if (is_file($file) && !is_link($file)) { unlink($file); }
    }
}
echo "[OK] Document version operation lifecycle integration passed.\n";
