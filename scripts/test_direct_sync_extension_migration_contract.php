<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/database/031_direct_sync_extensions.sql';
if (!is_file($path)) throw new RuntimeException('DIRECT_SYNC_EXTENSION_MIGRATION_MISSING');
$sql = (string) file_get_contents($path);
foreach (["ALTER TABLE dbo.documents ALTER COLUMN extension VARCHAR(64) NOT NULL", "CK_documents_extension", "031_direct_sync_extensions.sql", "schema_migrations"] as $required) {
    if (!str_contains($sql, $required)) throw new RuntimeException('DIRECT_SYNC_EXTENSION_MIGRATION_CONTRACT_MISSING:' . $required);
}
echo "DIRECT_SYNC_EXTENSION_MIGRATION_CONTRACT_OK\n";
