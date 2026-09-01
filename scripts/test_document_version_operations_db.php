<?php
declare(strict_types=1);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
function db_operation_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$database = portal_open_database(portal_config(false));
db_operation_assert(portal_document_version_operation_schema_available($database), 'Operation schema is unavailable.');
$fixture = $database->query(
    "SELECT TOP (1) d.document_id, d.status_code, LOWER(d.content_hash) AS content_hash,
            (SELECT TOP (1) user_id FROM dbo.auth_users WHERE role_code = 'admin' AND is_active = 1 ORDER BY user_id) AS user_id
     FROM dbo.documents d WHERE d.content_hash IS NOT NULL ORDER BY d.document_id"
)->fetch();
db_operation_assert($fixture !== false && (int) $fixture['user_id'] > 0, 'No safe operation fixture is available.');
$firstId = portal_document_version_uuid();
$secondId = portal_document_version_uuid();
$expected = strtolower((string) $fixture['content_hash']);
$target = hash('sha256', 'db-operation-constraint-target');
if ($target === $expected) { $target = hash('sha256', 'db-operation-constraint-target-2'); }
$duplicateRejected = false;
$database->beginTransaction();
try {
    $insert = $database->prepare(
        "INSERT INTO dbo.document_version_operations
            (operation_id, document_id, operation_type, status_code, source_status_code,
             expected_current_hash, target_content_hash, drive_sync_status, requested_by_user_id)
         VALUES (?, ?, 'restore', 'queued', ?, ?, ?, 'pending-confirmation', ?)"
    );
    $values = [(int) $fixture['document_id'], (string) $fixture['status_code'], $expected, $target, (int) $fixture['user_id']];
    $insert->execute([$firstId, ...$values]);
    portal_insert_document_version_operation_event($database, $firstId, 'queued', 'REQUEST_QUEUED', 'portal', 'DB contract fixture.', (int) $fixture['user_id']);
    try {
        $insert->execute([$secondId, ...$values]);
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    db_operation_assert($duplicateRejected, 'Second active operation for one document was not rejected.');
    $update = $database->prepare(
        "UPDATE dbo.document_version_operations
         SET status_code = 'running', started_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
         WHERE operation_id = ?"
    );
    $update->execute([$firstId]);
    db_operation_assert($update->rowCount() === 1, 'Application principal cannot update operation state.');
    $eventCount = $database->prepare('SELECT COUNT(*) FROM dbo.document_version_operation_events WHERE operation_id = ?');
    $eventCount->execute([$firstId]);
    db_operation_assert((int) $eventCount->fetchColumn() === 1, 'Operation event was not inserted.');
} finally {
    if ($database->inTransaction()) { $database->rollBack(); }
}
echo "[OK] Document version operation database constraints passed.\n";
