<?php
declare(strict_types=1);
function sqlite_driver_readiness_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
sqlite_driver_readiness_assert(str_contains($bootstrap,"in_array('sqlite', PDO::getAvailableDrivers(), true)"),'Portal readiness must verify PDO SQLite availability before serving.');
echo "SQLITE_DRIVER_READINESS_CONTRACT_OK\n";
