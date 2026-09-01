<?php
declare(strict_types=1);
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
function check_logout(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"[FAIL] $message\n");exit(1);}}
check_logout(str_contains($bootstrap,'<form method="post" action="\' . portal_url(\'logout\')')&&str_contains($bootstrap,'. portal_csrf_field()'),'logout control must remain a POST form with CSRF');
$start=strpos($index,'function portal_handle_logout');$end=$start===false?false:strpos($index,"\nfunction ",$start+10);$source=$start===false?'':substr($index,$start,$end===false?null:$end-$start);
check_logout(str_contains($source,"REQUEST_METHOD'] !== 'POST'")&&str_contains($source,'portal_assert_csrf();'),'logout must remain POST-only and validate CSRF before success');
check_logout(str_contains($source,'$user = portal_current_user($database);')&&str_contains($source,"portal_url('login')")&&str_contains($source,"portal_url('documents')"),'CSRF failure must inspect current session and recover to login or a current authenticated page');
check_logout(!str_contains($source,"portal_render_page('請求無效'")&&!str_contains($source,'請重新登入後再試一次。'),'logout CSRF mismatch must not strand the browser on a dead-end error page');
check_logout(substr_count($source,"true, 303")>=3,'logout success and both recovery paths must use 303 redirects');
check_logout(strpos($source,'portal_assert_csrf();')<strpos($source,'portal_audit($database, \'logout\''),'logout audit/session destruction must occur only after valid CSRF');
fwrite(STDOUT,"[OK] logout POST, CSRF, stale-session recovery, and redirect contracts passed.\n");
