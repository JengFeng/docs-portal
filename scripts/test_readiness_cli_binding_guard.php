<?php
declare(strict_types=1);
function readiness_cli_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/scripts/verify_portal_readiness.php');
readiness_cli_assert(str_contains($source,"DOCUMENT_BINDING=unavailable_in_cli"),'Readiness tool must report an absent IIS-only binding environment without crashing.');
readiness_cli_assert(str_contains($source,"if (\$bindingPath === '')"),'Readiness tool must guard the IIS-only binding lookup.');
echo "READINESS_CLI_BINDING_GUARD_CONTRACT_OK\n";
