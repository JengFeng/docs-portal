<?php
declare(strict_types=1);
$source = (string) file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
if (!str_contains($source, 'if ($directSyncMode && (string) $row[\'status_code\'] !== \'published\')')) {
    throw new RuntimeException('DIRECT_SYNC_DOES_NOT_REPUBLISH_EXISTING_DOCUMENTS');
}
if (!str_contains($source, "SET status_code = 'published'")) {
    throw new RuntimeException('DIRECT_SYNC_REPUBLISH_UPDATE_MISSING');
}
echo "DIRECT_SYNC_REPUBLISH_CONTRACT_OK\n";
