<?php
declare(strict_types=1);

function initializer_script_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = dirname(__DIR__) . '/scripts/initialize_portal_state.php';
initializer_script_assert(is_file($path), 'Missing one-shot SQLite initializer script.');
$source = (string) file_get_contents($path);
foreach ([
    "require_once dirname(__DIR__) . '/app/portal_state.php';",
    'portal_open_state_database_at($path)',
    'portal_state_initialize_schema($database)',
    'portal_initialize_first_admin($database, $username, $displayName, $password)',
    'random_bytes(24)',
    'FIRST_ADMIN_PASSWORD=',
] as $required) {
    initializer_script_assert(str_contains($source, $required), 'Initializer script contract missing: ' . $required);
}
foreach (['$password = (string) ($argv', 'password='] as $forbidden) {
    initializer_script_assert(!str_contains($source, $forbidden), 'Initializer must not accept password text through command arguments: ' . $forbidden);
}
echo "FIRST_ADMIN_SCRIPT_CONTRACT_OK\n";
