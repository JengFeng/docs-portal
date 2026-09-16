<?php
declare(strict_types=1);
function config_state_binding_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$source=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
config_state_binding_assert(str_contains($source,'portal_state_binding_read(portal_state_binding_path())'),'Portal configuration must pass the fixed state binding path.');
config_state_binding_assert(!str_contains($source,'portal_state_binding_read();'),'Portal configuration must not call the required-path binding reader without an argument.');
echo "PORTAL_CONFIG_STATE_BINDING_CALL_CONTRACT_OK\n";
