<?php
declare(strict_types=1);
function session_schema_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
session_schema_assert(str_contains($source,'must_change_password'),'Session lookup must select the SQLite schema field.');
session_schema_assert(!str_contains($source,'password_change_required'),'Session lookup must not reference a nonexistent SQLite column.');
echo "SQLITE_SESSION_SCHEMA_CONTRACT_OK\n";
