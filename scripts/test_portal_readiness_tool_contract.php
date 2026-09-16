<?php
declare(strict_types=1);

function readiness_script_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/verify_portal_readiness.php');
foreach ([
    "PHP_SAPI !== 'cli'",
    'portal_state_binding_read(',
    'portal_document_library_binding_read(',
    'FIRST_ADMIN_REQUIRED',
    'SQLite audit chain',
] as $required) {
    readiness_script_assert(str_contains($source, $required), 'Readiness tool contract missing: ' . $required);
}
foreach (['password', 'PORTAL_DB_', 'sqlsrv', 'dbo.'] as $forbidden) {
    readiness_script_assert(!str_contains(strtolower($source), strtolower($forbidden)), 'Readiness tool must not expose or depend on: ' . $forbidden);
}
echo "PORTAL_READINESS_TOOL_CONTRACT_OK\n";
