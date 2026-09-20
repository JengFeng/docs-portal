<?php
declare(strict_types=1);
function health_poll_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$page=(string)file_get_contents(dirname(__DIR__).'/app/admin_pages.php');$javascript=(string)file_get_contents(dirname(__DIR__).'/assets/app.js');
health_poll_assert(str_contains($index,"'admin_library_health_data'"),'Health JSON route must be allowlisted.');
health_poll_assert(str_contains($index,'portal_handle_admin_library_health_data('),'Health JSON route must be dispatched.');
health_poll_assert(str_contains($page,'function portal_handle_admin_library_health_data('),'Health JSON handler must exist.');
health_poll_assert(!str_contains($page,'location.reload()'),'Health polling must not reload the page.');
health_poll_assert(str_contains($page,'data-health-poll-url'),'Health page must expose its allowlisted polling endpoint as data.');
health_poll_assert(str_contains($javascript,'setInterval'),'Health page must poll in place every 60 seconds through the CSP-allowed external script.');
health_poll_assert(!str_contains($page,'<script'),'Health page must not emit inline script blocked by the portal CSP.');
echo "ADMIN_LIBRARY_HEALTH_POLLING_CONTRACT_OK\n";
