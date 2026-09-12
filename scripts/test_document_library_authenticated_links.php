<?php
declare(strict_types=1);
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
if (str_contains($index, "? portal_direct_sync_public_url((string) \$document['relative_path'])")) {
    throw new RuntimeException('DIRECT_SYNC_BYPASSES_AUTHENTICATED_DOCUMENT_ROUTE');
}
if (!str_contains($index, "portal_url('view', ['id' => (string) \$document['public_id']])")) {
    throw new RuntimeException('AUTHENTICATED_DOCUMENT_ROUTE_MISSING');
}
echo "AUTHENTICATED_DOCUMENT_LINK_CONTRACT_OK\n";
