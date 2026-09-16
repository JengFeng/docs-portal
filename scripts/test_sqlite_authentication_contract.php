<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';
require_once dirname(__DIR__) . '/app/sqlite_accounts.php';
require_once dirname(__DIR__) . '/app/sqlite_auth.php';

function sqlite_auth_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = 'C:\\TWWATER\\runtime\\portal-state\\auth-contract.sqlite';
@unlink($fixture);
@unlink($fixture . '-wal');
@unlink($fixture . '-shm');

try {
    $database = portal_open_state_database_at($fixture);
    portal_state_initialize_schema($database);
    $admin = portal_initialize_first_admin($database, 'root-admin', 'Root Administrator', 'FixtureOnly-Password-2026');
    $local = portal_create_account($database, (int) $admin['user_id'], [
        'username' => 'local-reader', 'display_name' => 'Local Reader', 'role_code' => 'reader',
        'login_mode' => 'local_only', 'password' => 'ReaderOnly-Password-2026', 'google_email' => '',
    ]);
    $google = portal_create_account($database, (int) $admin['user_id'], [
        'username' => 'google-reader', 'display_name' => 'Google Reader', 'role_code' => 'reader',
        'login_mode' => 'google_only', 'password' => '', 'google_email' => 'google.reader@example.test',
    ]);

    sqlite_auth_test_assert(portal_sqlite_login_mode_allows_local('local_only'), 'local_only must allow local login.');
    sqlite_auth_test_assert(!portal_sqlite_login_mode_allows_google('local_only'), 'local_only must reject Google login.');
    sqlite_auth_test_assert(!portal_sqlite_login_mode_allows_local('google_only'), 'google_only must reject local login.');
    sqlite_auth_test_assert(portal_sqlite_login_mode_allows_google('google_only'), 'google_only must allow Google login.');
    sqlite_auth_test_assert(portal_sqlite_login_mode_allows_local('local_and_google'), 'combined mode must allow local login.');
    sqlite_auth_test_assert(portal_sqlite_login_mode_allows_google('local_and_google'), 'combined mode must allow Google login.');

    $authenticated = portal_sqlite_authenticate_local($database, 'local-reader', 'ReaderOnly-Password-2026');
    sqlite_auth_test_assert($authenticated['user_id'] === $local['user_id'], 'local_only user must authenticate locally.');
    sqlite_auth_test_assert((int) $authenticated['failed_login_count'] === 0, 'successful login must clear failure count.');

    try {
        portal_sqlite_authenticate_local($database, 'google-reader', 'ReaderOnly-Password-2026');
        throw new RuntimeException('google_only account must reject local authentication.');
    } catch (RuntimeException $exception) {
        sqlite_auth_test_assert($exception->getMessage() !== 'google_only account must reject local authentication.', 'google_only local login was accepted.');
    }

    try {
        portal_sqlite_authenticate_local($database, 'local-reader', 'wrong-password');
        throw new RuntimeException('incorrect password must be rejected.');
    } catch (RuntimeException $exception) {
        sqlite_auth_test_assert($exception->getMessage() !== 'incorrect password must be rejected.', 'incorrect password was accepted.');
    }
    $afterFailure = portal_sqlite_find_user_by_username($database, 'local-reader');
    sqlite_auth_test_assert((int) $afterFailure['failed_login_count'] === 1, 'failed local login must increment failure count.');
} finally {
    unset($database);
    @unlink($fixture);
    @unlink($fixture . '-wal');
    @unlink($fixture . '-shm');
}

echo "SQLITE_AUTHENTICATION_CONTRACT_OK\n";
