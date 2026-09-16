<?php
declare(strict_types=1);
function breadcrumb_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
$js=(string)file_get_contents(dirname(__DIR__).'/assets/app.js');
breadcrumb_assert(str_contains($index,'function portal_render_explorer_breadcrumb('),'Breadcrumb renderer is missing.');
breadcrumb_assert(str_contains($index,'data-explorer-breadcrumb'),'Breadcrumb target is missing.');
breadcrumb_assert(str_contains($index,'data-explorer-folder'),'Breadcrumb links are missing.');
breadcrumb_assert(str_contains($js,'portalRenderBreadcrumb')||str_contains($js,'renderBreadcrumb'),'Client breadcrumb rendering is missing.');
echo "DOCUMENT_EXPLORER_BREADCRUMB_CONTRACT_OK\n";
