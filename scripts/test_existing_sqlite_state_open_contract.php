<?php
declare(strict_types=1);
function existing_state_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$state=(string)file_get_contents(dirname(__DIR__).'/app/portal_state.php');$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$init=(string)file_get_contents(dirname(__DIR__).'/scripts/initialize_portal_state.php');
existing_state_assert(str_contains($state,'function portal_open_existing_state_database('),'Existing-only state opener missing.');
existing_state_assert(str_contains($index,'portal_open_existing_state_database()'),'Web runtime must refuse missing state instead of creating it.');
existing_state_assert(str_contains($init,'portal_open_state_database_at($path)'),'Local initializer must remain the only database creator.');
echo "EXISTING_SQLITE_STATE_OPEN_CONTRACT_OK\n";
