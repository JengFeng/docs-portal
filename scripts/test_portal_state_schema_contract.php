<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';

function portal_state_schema_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\schema-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);

    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['portal_state_schema', 'auth_users', 'auth_management_audit_events', 'portal_state_settings'] as $table) {
        portal_state_schema_test_assert(in_array($table, $tables, true), 'Missing SQLite state table: ' . $table);
    }

    $auditTriggers = $database->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'auth_management_audit_events' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    portal_state_schema_test_assert(
        $auditTriggers === ['trg_auth_management_audit_events_no_delete', 'trg_auth_management_audit_events_no_update'],
        'Audit UPDATE and DELETE must be rejected by SQLite triggers.'
    );

    $journalMode = strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn());
    portal_state_schema_test_assert($journalMode === 'wal', 'SQLite state database must use WAL mode.');

    $database->exec("INSERT INTO auth_management_audit_events (occurred_at, actor_user_id, event_type, target_user_id, summary_json, previous_event_hash, event_hash) VALUES ('2026-09-15T00:00:00Z', NULL, 'fixture', NULL, '{}', NULL, 'fixture-hash')");
    try {
        $database->exec("DELETE FROM auth_management_audit_events WHERE event_hash = 'fixture-hash'");
        throw new RuntimeException('Audit DELETE must be blocked.');
    } catch (PDOException) {
    }
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "PORTAL_STATE_SCHEMA_CONTRACT_OK\n";
