<?php
declare(strict_types=1);

function direct_iis_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$configPath = $root . '/web.config';
$xml = file_get_contents($configPath);
direct_iis_assert(is_string($xml) && $xml !== '', 'Root web.config is unreadable.');
direct_iis_assert(
    preg_match('/<add\s+segment=["\']document-library["\']\s*\/>/i', $xml) === 1,
    'Root web.config does not hide document-library.'
);
direct_iis_assert(
    !is_file($root . '/document-library/web.config'),
    'Child document-library web.config must not be used.'
);
direct_iis_assert(
    preg_match('/<directoryBrowse\s+enabled=["\']false["\']\s*\/>/i', $xml) === 1,
    'Directory browsing is not disabled.'
);

echo "[OK] Direct document root IIS isolation contract passed.\n";
