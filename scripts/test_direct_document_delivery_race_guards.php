<?php
declare(strict_types=1);
function direct_delivery_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
direct_delivery_assert(str_contains($bootstrap,'if(count($files)>=PORTAL_DOCUMENT_LIST_LIMIT)break;'),'Document list must enforce the limit before appending.');
direct_delivery_assert(str_contains($index,'$stat=fstat($h);'),'Direct download must derive metadata from the opened handle.');
direct_delivery_assert(!str_contains($index,'filesize($resolved[\'path\'])'),'Direct download must not use a second pathname stat for Content-Length.');
echo "DIRECT_DOCUMENT_DELIVERY_RACE_GUARDS_OK\n";
