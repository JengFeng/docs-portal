<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';
require_once dirname(__DIR__) . '/app/sqlite_auth.php';

function google_auth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\google-auth-contract.sqlite';
@unlink($fixture); @unlink($fixture . '-wal'); @unlink($fixture . '-shm');
try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    $admin = portal_initialize_first_admin($database, 'root-admin', 'Root Administrator', 'FixtureOnly-Password-2026');
    $google = portal_create_account($database, (int) $admin['user_id'], [
        'username' => 'google-reader', 'display_name' => 'Google Reader', 'role_code' => 'reader',
        'login_mode' => 'google_only', 'password' => '', 'google_email' => 'google.reader@example.test',
    ]);

    $authenticated = portal_sqlite_authenticate_google($database, 'google.reader@example.test', 'immutable-google-subject-001');
    google_auth_test_assert($authenticated['user_id'] === $google['user_id'], 'Pre-bound Google account must authenticate.');
    google_auth_test_assert($authenticated['google_subject'] === 'immutable-google-subject-001', 'First verified Google login must pin subject.');

    try {
        portal_sqlite_authenticate_google($database, 'google.reader@example.test', 'different-google-subject');
        throw new RuntimeException('Different Google subject must be rejected after pinning.');
    } catch (RuntimeException $exception) {
        google_auth_test_assert($exception->getMessage() !== 'Different Google subject must be rejected after pinning.', 'Different Google subject was accepted.');
    }

    $event = $database->query("SELECT event_type FROM auth_management_audit_events ORDER BY event_id DESC LIMIT 1")->fetchColumn();
    google_auth_test_assert($event === 'google_subject_pinned', 'First Google authentication must append a safe pin audit event.');
} finally {
    unset($database); @unlink($fixture); @unlink($fixture . '-wal'); @unlink($fixture . '-shm');
}
echo "SQLITE_GOOGLE_AUTHENTICATION_CONTRACT_OK\n";
