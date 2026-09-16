<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';

function audit_integrity_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\audit-integrity-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    portal_state_append_audit_event($database, null, 'fixture_one', null, ['ordinal' => 1]);
    portal_state_append_audit_event($database, null, 'fixture_two', null, ['ordinal' => 2]);
    portal_state_verify_audit_chain($database);

    $database->exec('DROP TRIGGER trg_auth_management_audit_events_no_update');
    $database->exec("UPDATE auth_management_audit_events SET summary_json = '{\"ordinal\":999}' WHERE event_type = 'fixture_two'");
    try {
        portal_state_verify_audit_chain($database);
        throw new RuntimeException('Tampered audit chain must fail verification.');
    } catch (RuntimeException $exception) {
        audit_integrity_test_assert($exception->getMessage() !== 'Tampered audit chain must fail verification.', 'Tampered audit chain was accepted.');
    }
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "SQLITE_AUDIT_INTEGRITY_CONTRACT_OK\n";
