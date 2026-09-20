<?php
declare(strict_types=1);

function page_cache_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = (string) file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
$start = strpos($source, 'function portal_render_page(');
page_cache_assert($start !== false, 'Portal page renderer must exist.');
$renderer = substr($source, $start, 4000);
page_cache_assert(str_contains($renderer, "header('Cache-Control: no-store, no-cache, must-revalidate')"), 'Authenticated page renderer must prevent stale HTML cache.');
page_cache_assert(str_contains($renderer, "header('Pragma: no-cache')"), 'Authenticated page renderer must prevent legacy cache reuse.');
echo "AUTHENTICATED_PAGE_NO_STORE_CONTRACT_OK\n";
