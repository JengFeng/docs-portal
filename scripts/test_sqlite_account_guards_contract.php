<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';

function account_guards_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\account-guards-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    $admin = portal_initialize_first_admin($database, 'root-admin', 'Root Administrator', 'FixtureOnly-Password-2026');
    $reader = portal_create_account($database, (int) $admin['user_id'], [
        'username' => 'reader-one', 'display_name' => 'Reader One', 'role_code' => 'reader',
        'login_mode' => 'local_only', 'password' => 'ReaderOnly-Password-2026', 'google_email' => '',
    ]);

    $updated = portal_update_account($database, (int) $admin['user_id'], (int) $reader['user_id'], [
        'display_name' => 'Reader Updated', 'role_code' => 'reader', 'login_mode' => 'google_only',
        'google_email' => 'reader.one@example.test', 'is_enabled' => true,
    ]);
    account_guards_test_assert($updated['display_name'] === 'Reader Updated', 'Account update must read back display name.');
    account_guards_test_assert($updated['login_mode'] === 'google_only', 'Account update must read back login mode.');
    account_guards_test_assert((int) $updated['is_enabled'] === 1, 'Account update must read back enabled state.');

    foreach ([
        ['target' => $admin, 'input' => ['display_name' => 'Root Administrator', 'role_code' => 'reader', 'login_mode' => 'local_only', 'google_email' => '', 'is_enabled' => true], 'message' => 'Administrator must not demote itself.'],
        ['target' => $admin, 'input' => ['display_name' => 'Root Administrator', 'role_code' => 'admin', 'login_mode' => 'local_only', 'google_email' => '', 'is_enabled' => false], 'message' => 'Administrator must not disable itself.'],
        ['target' => $admin, 'input' => ['display_name' => 'Root Administrator', 'role_code' => 'admin', 'login_mode' => 'google_only', 'google_email' => '', 'is_enabled' => true], 'message' => 'Administrator must not remove every usable login method.'],
    ] as $case) {
        try {
            portal_update_account($database, (int) $admin['user_id'], (int) $case['target']['user_id'], $case['input']);
            throw new RuntimeException($case['message']);
        } catch (RuntimeException $exception) {
            account_guards_test_assert($exception->getMessage() !== $case['message'], $case['message']);
        }
    }

    try {
        portal_update_account($database, (int) $admin['user_id'], (int) $admin['user_id'], [
            'display_name' => 'Root Administrator', 'role_code' => 'reader', 'login_mode' => 'local_only', 'google_email' => '', 'is_enabled' => true,
        ]);
        throw new RuntimeException('Last active administrator must not be demoted.');
    } catch (RuntimeException $exception) {
        account_guards_test_assert($exception->getMessage() !== 'Last active administrator must not be demoted.', 'Last active administrator was demoted.');
    }

    $event = $database->query("SELECT event_type FROM auth_management_audit_events ORDER BY event_id DESC LIMIT 1")->fetchColumn();
    account_guards_test_assert($event === 'account_updated', 'Account update must append an audit event.');
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "SQLITE_ACCOUNT_GUARDS_CONTRACT_OK\n";
