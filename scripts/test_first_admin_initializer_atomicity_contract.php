<?php
declare(strict_types=1);
function initializer_atomic_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/scripts/initialize_portal_state.php');
initializer_atomic_assert(str_contains($source,"if (is_file(\$path) || is_link(\$path))"),'Initializer must reject an existing state database before opening it.');
initializer_atomic_assert(str_contains($source,'@unlink($path);'),'Initializer must remove a partial state database if initialization fails.');
initializer_atomic_assert(str_contains($source,'@unlink($path . \'-wal\');'),'Initializer must remove a partial WAL file if initialization fails.');
echo "FIRST_ADMIN_INITIALIZER_ATOMICITY_CONTRACT_OK\n";
