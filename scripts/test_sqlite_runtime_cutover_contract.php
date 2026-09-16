<?php
declare(strict_types=1);

function cutover_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
foreach ([
    "'documents', 'login', 'google_login', 'google_callback', 'logout', 'view', 'download', 'inline', 'admin', 'admin_users', 'admin_library_health', 'admin_library_health_data', 'admin_audit'",
    'portal_open_existing_state_database()',
    'portal_sqlite_authenticate_local(',
    'portal_render_admin_users_page(',
    'portal_render_admin_audit_page(',
] as $required) {
    cutover_assert(str_contains($index, $required), 'SQLite cutover route contract missing: ' . $required);
}
foreach ([
    'function portal_current_user(PDO $database): ?array',
    'FROM auth_users',
    'function portal_complete_login_session(array $user): void',
    'function portal_direct_library_documents(array $config, array $filters): array',
    'function portal_direct_document_path(array $config, string $id): ?array',
] as $required) {
    cutover_assert(str_contains($bootstrap, $required), 'SQLite bootstrap contract missing: ' . $required);
}
foreach (['dbo.', 'sqlsrv:', 'PDO::SQLSRV', 'PORTAL_DB_', 'portal_open_database(', 'site_feedback.php'] as $forbidden) {
    cutover_assert(!str_contains($index . "\n" . $bootstrap, $forbidden), 'SQLite runtime must retire: ' . $forbidden);
}
$appFiles = glob($root . '/app/*.php') ?: [];
foreach ($appFiles as $appFile) {
    $source = (string) file_get_contents($appFile);
    foreach (['dbo.', 'sqlsrv:', 'PDO::SQLSRV', 'PORTAL_DB_', 'portal_open_database(', 'site_feedback.php'] as $forbidden) {
        cutover_assert(!str_contains($source, $forbidden), 'SQL runtime identifier remains in deployable app source: ' . basename($appFile) . ' :: ' . $forbidden);
    }
}

echo "SQLITE_RUNTIME_CUTOVER_CONTRACT_OK\n";
