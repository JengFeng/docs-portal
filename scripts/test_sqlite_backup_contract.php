<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_backup.php';

function sqlite_backup_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = 'C:\\TWWATER\\runtime\\portal-state\\backup-source-contract.sqlite';
$backupRoot = 'C:\\TWWATER\\runtime\\database-backups\\sqlite\\backup-contract';
@mkdir($backupRoot, 0770, true);
foreach (glob($backupRoot . '\\*') ?: [] as $file) @unlink($file);
@unlink($source);
@unlink($source . '-wal');
@unlink($source . '-shm');

try {
    $database = portal_open_state_database_at($source);
    portal_state_initialize_schema($database);
    portal_state_append_audit_event($database, null, 'backup_fixture', null, ['fixture' => true]);
    $backup = portal_state_create_verified_backup($database, $backupRoot, 'fixture');

    sqlite_backup_test_assert(is_file($backup['path']), 'SQLite backup file must be created.');
    sqlite_backup_test_assert(is_file($backup['manifest_path']), 'SQLite backup manifest must be created.');
    sqlite_backup_test_assert($backup['integrity'] === 'ok', 'SQLite backup must pass integrity check.');
    sqlite_backup_test_assert(preg_match('/\A[0-9a-f]{64}\z/', $backup['sha256']) === 1, 'Backup SHA-256 must be recorded.');

    $copy = new PDO('sqlite:' . $backup['path']);
    sqlite_backup_test_assert((string) $copy->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Backup must be independently readable.');
    portal_state_verify_audit_chain($copy);
} finally {
    unset($copy, $database);
    @unlink($source);
    @unlink($source . '-wal');
    @unlink($source . '-shm');
    foreach (glob($backupRoot . '\\*') ?: [] as $file) @unlink($file);
    @rmdir($backupRoot);
}

echo "SQLITE_BACKUP_CONTRACT_OK\n";
