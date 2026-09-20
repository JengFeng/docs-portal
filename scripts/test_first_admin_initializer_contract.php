<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';

function first_admin_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\first-admin-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    $user = portal_initialize_first_admin($database, 'initial-admin', 'Initial Administrator', 'FixtureOnly-Password-2026');

    first_admin_test_assert($user['username'] === 'initial-admin', 'Initializer must return the created administrator.');
    first_admin_test_assert($user['role_code'] === 'admin', 'First account must be administrator.');
    first_admin_test_assert((int) $user['must_change_password'] === 1, 'First administrator must change its password on first login.');
    first_admin_test_assert(password_verify('FixtureOnly-Password-2026', (string) $user['password_hash']), 'Initializer must store a PHP password hash.');

    $event = $database->query("SELECT event_type, summary_json FROM auth_management_audit_events ORDER BY event_id DESC LIMIT 1")->fetch();
    first_admin_test_assert($event['event_type'] === 'first_admin_initialized', 'Initializer must append an audit event.');
    first_admin_test_assert(!str_contains($event['summary_json'], 'FixtureOnly-Password-2026'), 'Audit data must never contain a password.');

    try {
        portal_initialize_first_admin($database, 'second-admin', 'Second Administrator', 'FixtureOnly-Password-2026');
        throw new RuntimeException('Second initialization must be rejected.');
    } catch (RuntimeException $exception) {
        first_admin_test_assert($exception->getMessage() !== 'Second initialization must be rejected.', 'Second initialization was accepted.');
    }
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "FIRST_ADMIN_INITIALIZER_CONTRACT_OK\n";
