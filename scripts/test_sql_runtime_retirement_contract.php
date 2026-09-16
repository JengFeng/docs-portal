<?php
declare(strict_types=1);

function sql_retirement_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$runtimeFiles = [
    'index.php',
    'app/bootstrap.php',
    'app/admin_accounts.php',
    'app/admin_audit.php',
    'app/admin_pages.php',
    'app/document_library_health.php',
];
$forbidden = [
    'PDO::SQLSRV',
    'portal_open_database(',
    'portal_database_password_from_environment(',
    'PORTAL_DB_',
];

$violations = [];
foreach ($runtimeFiles as $relativePath) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    sql_retirement_assert(is_file($path), 'Missing retained runtime file: ' . $relativePath);
    $source = (string) file_get_contents($path);
    foreach ($forbidden as $token) {
        if (str_contains($source, $token)) {
            $violations[] = $relativePath . ': ' . $token;
        }
    }
}

sql_retirement_assert(
    $violations === [],
    'Active TWWATER runtime must not depend on SQL Server: ' . implode('; ', $violations)
);

echo "SQL_RUNTIME_RETIREMENT_CONTRACT_OK\n";
