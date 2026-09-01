<?php
declare(strict_types=1);
function policy_ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$root=dirname(__DIR__);
$retirementModule=$root.'/app/staging_retirement.php';
policy_ok(is_file($retirementModule),'staging retirement policy module must exist');
require_once $retirementModule;
$bootstrap=(string)file_get_contents($root.'/app/bootstrap.php');
$index=(string)file_get_contents($root.'/index.php');
$image=(string)file_get_contents($root.'/app/image_library.php');
$feedback=(string)file_get_contents($root.'/app/site_feedback.php');
$presentation=(string)file_get_contents($root.'/app/presentation.php');
$presentationJs=(string)file_get_contents($root.'/assets/presentation.js');
$css=(string)file_get_contents($root.'/assets/app.css');

$navStart=strpos($bootstrap,'$navigation = \'<nav class="site-nav"');
$navEnd=$navStart===false?false:strpos($bootstrap,"\$navigation .= '</nav>';",$navStart);
$nav=($navStart!==false&&$navEnd!==false)?substr($bootstrap,$navStart,$navEnd-$navStart):'';
policy_ok($nav!==''&&!str_contains($nav,"\$navigation .= '<a href=\"' . portal_url('feedback')")&&!str_contains($nav,'暫存預覽'),'main navigation must hide feedback and staging links');
policy_ok(str_contains($bootstrap,"if (portal_is_admin(\$user))")&&str_contains($bootstrap,'class="site-feedback-shortcut"')&&str_contains($bootstrap,'aria-label="畫面修改需求"')&&str_contains($bootstrap,'$navigation . $feedbackShortcut . $fontControl'),'administrator-only feedback must be an accessible icon shortcut beside font-size controls');
policy_ok(str_contains($css,'.site-feedback-shortcut{')&&str_contains($css,'.site-feedback-shortcut:focus-visible'),'feedback icon shortcut must have visible desktop, keyboard and touch styling');

$guard=strpos($index,'$disabledStagingActions = portal_disabled_staging_actions();');
$legacy=strpos($index,"if (\$action === 'staging') {");
$auth=strpos($index,'$user = portal_require_user($database);');
policy_ok($auth!==false&&$guard!==false&&$legacy!==false&&$auth<$guard&&$guard<$legacy,'all legacy staging routes must be intercepted after authentication and before upload handlers');
$expectedRoutes=['staging','staging_workspace','staging_chunk','staging_finalize','staging_preview_asset','staging_confirm','staging_return'];
policy_ok(portal_disabled_staging_actions()===$expectedRoutes,'runtime retirement policy must expose exactly the seven expected routes');
foreach($expectedRoutes as $route){
    $response=portal_disabled_staging_response($route);
    policy_ok(is_array($response)&&$response['status']===410&&str_contains($response['body'],'正式文件庫'),'runtime retirement response must fail closed for '.$route);
}
policy_ok(portal_disabled_staging_response('documents')===null,'unrelated routes must not be intercepted');
$guardExit=strpos($index,'exit;',$guard);
foreach($expectedRoutes as $route){$handler=strpos($index,"if (\$action === '{$route}') {");policy_ok($handler!==false&&$guardExit!==false&&$guardExit<$handler,'retirement exit must precede legacy handler '.$route);}
$guardSegment=($guard!==false&&$legacy!==false)?substr($index,$guard,$legacy-$guard):'';
policy_ok(substr_count($guardSegment,'exit;')>=2,'page and JSON retirement responses must both terminate before legacy handlers');
policy_ok(str_contains($index,"http_response_code((int) \$disabledStagingResponse['status'])")&&str_contains($index,'網站上傳已停用')&&str_contains((string)portal_disabled_staging_response('staging')['body'],'正式文件庫'),'disabled staging routes must fail closed and explain the direct-root Phase-1 policy');
policy_ok(str_contains($index,'本網站目前直接讀取受保護的正式文件庫'),'document library must identify the protected direct root');
policy_ok(!str_contains($image,"portal_url('staging'")&&!str_contains($image,'上傳圖片至暫存預覽')&&str_contains($image,'資料由受保護正式文件庫讀取')&&str_contains($image,'網站不提供上傳'),'image library must remove website upload links and explain direct-root read-only operation');
policy_ok(!str_contains($presentation,"'staging_url'")&&!str_contains($presentationJs,'open-staging')&&!str_contains($presentationJs,'lastCreatedRequestStagingUrl')&&str_contains($presentationJs,'正式文件庫'),'presentation change requests must no longer reopen the retired staging uploader');
policy_ok(!str_contains($feedback,"\$actions['staging']")&&!str_contains($feedback,"'image_review','staging','admin'")&&!str_contains($feedback,"'image_review','staging','admin_document'"),'disabled staging pages must not remain selectable or embeddable feedback targets');

echo "[OK] Direct-root read-only upload policy and feedback header shortcut contracts passed.\n";
