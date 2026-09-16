<?php
declare(strict_types=1);
function explorer_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$js=(string)file_get_contents(dirname(__DIR__).'/assets/app.js');
foreach(['function portal_direct_library_folder_tree(','function portal_direct_folder_id(','function portal_render_explorer_tree_node('] as $required)explorer_assert(str_contains($bootstrap.$index,$required),'Explorer runtime missing: '.$required);
foreach(['data-explorer-tree','data-explorer-folder','data-explorer-search'] as $required)explorer_assert(str_contains($index,$required),'Explorer markup missing: '.$required);
explorer_assert(str_contains($js,'twwater-explorer-folder'),'Explorer local folder state key missing.');
foreach(['localStorage','data-explorer-folder','data-explorer-search'] as $required)explorer_assert(str_contains($js,$required),'Explorer client behavior missing: '.$required);
echo "DOCUMENT_EXPLORER_CONTRACT_OK\n";
