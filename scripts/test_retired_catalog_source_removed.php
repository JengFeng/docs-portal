<?php
declare(strict_types=1);
function catalog_source_assert(bool $condition,string $message): void { if (!$condition) { fwrite(STDERR,"[FAIL] {$message}\n"); exit(1); } }
$root=dirname(__DIR__);
$files=[$root.'/index.php',$root.'/app/bootstrap.php'];
$forbidden=['dbo.documents','document_versions','document_version_contents','portal_sync_documents','document_mutation'];
foreach($files as $file){$source=(string)file_get_contents($file);foreach($forbidden as $needle)catalog_source_assert(!str_contains($source,$needle),basename($file).' retains dropped catalog identifier: '.$needle);}
echo "RETIRED_CATALOG_SOURCE_REMOVED_OK\n";
