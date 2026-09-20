<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/portal_state.php';

function portal_state_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$bindingPath = portal_state_binding_path();
$binding = portal_state_binding_read($bindingPath);

portal_state_test_assert($binding['schema_version'] === 1, 'SQLite binding must use schema version 1.');
portal_state_test_assert(
    $binding['sqlite_path'] === 'C:\\TWWATER\\runtime\\portal-state\\twwater-portal.sqlite',
    'SQLite state path must be the approved protected runtime path.'
);
portal_state_test_assert(
    $binding['backup_root'] === 'C:\\TWWATER\\runtime\\database-backups\\sqlite',
    'SQLite backup root must be the approved protected backup path.'
);
portal_state_test_assert($binding['google_login_enabled'] === false, 'Google login must default to disabled.');

$invalidPath = 'C:\\TWWATER\\runtime\\portal-state\\portal-state-binding-invalid-test.json';
file_put_contents($invalidPath, '{"schema_version":1,"sqlite_path":"C:\\\\unsafe.sqlite","backup_root":"C:\\\\TWWATER\\\\runtime\\\\database-backups\\\\sqlite","google_login_enabled":false}');
try {
    portal_state_binding_read($invalidPath);
    throw new RuntimeException('Unsafe SQLite binding path must fail closed.');
} catch (RuntimeException $exception) {
    portal_state_test_assert(
        $exception->getMessage() !== 'Unsafe SQLite binding path must fail closed.',
        'Unsafe SQLite binding path was accepted.'
    );
} finally {
    @unlink($invalidPath);
}

echo "PORTAL_STATE_BINDING_CONTRACT_OK\n";
