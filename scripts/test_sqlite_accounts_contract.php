<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';

function sqlite_accounts_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\accounts-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    $admin = portal_initialize_first_admin($database, 'root-admin', 'Root Administrator', 'FixtureOnly-Password-2026');
    $reader = portal_create_account($database, (int) $admin['user_id'], [
        'username' => 'reader-one',
        'display_name' => 'Reader One',
        'role_code' => 'reader',
        'login_mode' => 'local_only',
        'password' => 'ReaderOnly-Password-2026',
        'google_email' => '',
    ]);

    sqlite_accounts_test_assert($reader['username'] === 'reader-one', 'Created account username must read back.');
    sqlite_accounts_test_assert($reader['role_code'] === 'reader', 'Created account role must read back.');
    sqlite_accounts_test_assert($reader['login_mode'] === 'local_only', 'Created account login mode must read back.');
    sqlite_accounts_test_assert((int) $reader['is_enabled'] === 1, 'Created account must be enabled.');
    sqlite_accounts_test_assert(password_verify('ReaderOnly-Password-2026', (string) $reader['password_hash']), 'Created account must store a password hash.');

    $event = $database->query("SELECT event_type, summary_json FROM auth_management_audit_events ORDER BY event_id DESC LIMIT 1")->fetch();
    sqlite_accounts_test_assert($event['event_type'] === 'account_created', 'Account creation must append an audit event.');
    sqlite_accounts_test_assert(!str_contains($event['summary_json'], 'ReaderOnly-Password-2026'), 'Audit must not contain account password text.');
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "SQLITE_ACCOUNTS_CONTRACT_OK\n";
