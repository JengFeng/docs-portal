<?php
declare(strict_types=1);
function sync_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$admin=(string)file_get_contents(dirname(__DIR__).'/app/admin_pages.php');$state=(string)file_get_contents(dirname(__DIR__).'/app/admin_sync.php');$javascript=(string)file_get_contents(dirname(__DIR__).'/assets/app.js');
foreach(['admin_sync','admin_sync_data','admin_sync_request','admin_sync_confirm','admin_sync_cancel'] as $route)sync_assert(str_contains($index,"'$route'"),'Missing sync route: '.$route);
foreach(['portal_render_admin_sync_page','portal_sync_request_create','portal_sync_request_cancel','portal_render_admin_sync_error','Google Drive → 網站 document-library','網站 document-library → Google Drive','雙向同步'] as $required)sync_assert(str_contains($admin.$state,$required),'Missing sync control: '.$required);
foreach(['portal_url(\'admin_sync\')','同步管理'] as $required)sync_assert(str_contains($admin,$required),'Admin overview must expose sync management: '.$required);
foreach(['目前同步作業','同步歷史紀錄','sync-action-grid','sync-history-query','<summary>查詢條件</summary>','data-sync-current-operation','data-sync-history','history_cursor_time','history_cursor_id','更多歷史紀錄','Asia/Taipei','等待處理','執行中','預覽完成','同步成功','同步失敗','portal_sync_status_label','name="action" value="admin_sync"','portal_sync_preview_confirmable',"unset(\$data['items'])"] as $required)sync_assert(str_contains($admin,$required),'Sync renderer/API must protect required UI boundary: '.$required);
sync_assert(str_contains($admin,'data-sync-poll-url')&&str_contains($admin,"portal_url('admin_sync_data')"),'Sync page must expose only its allowlisted polling endpoint as data.');
foreach(['portal_sync_selected_direction','sync_direction','portal_url(\'admin_sync\',[\'sync_direction\'=>$direction])',' selected'] as $required)sync_assert(str_contains($admin,$required),'Sync direction must survive the post-redirect-get cycle: '.$required);
foreach(['setInterval(','data-sync-request-form','addEventListener(\'submit\'','aria-busy','正在送出同步預覽請求','同步狀態暫時無法更新','data-sync-last-updated'] as $required)sync_assert(str_contains($javascript.$admin,$required),'External sync controller must provide CSP-safe polling and immediate submit feedback: '.$required);
sync_assert(!str_contains($admin,'<script'),'Sync page must not emit inline script blocked by the portal CSP.');
foreach(['sync-service','requests','results','history','cancel','schema_version','portal_sync_valid_preview','portal_sync_confirmable_json_rows','portal_sync_preview_confirmable','portal_sync_metrics_valid','preview_consumed','requested_by','cancel_requested_by','portal_sync_execute_lock','LOCK_EX','portal_sync_active_owned_request','sync_request_publish_failed'] as $required)sync_assert(str_contains($state,$required),'Missing protected sync runtime boundary: '.$required);
sync_assert(!str_contains($state,'legacy_service_without_heartbeat'),'Missing heartbeat must fail closed.');
sync_assert(str_contains($state,"\$root . '\\\\results\\\\service-status.json'"),'Heartbeat must be readable through the existing results-directory ACL.');
$auditPosition=strpos($state,"portal_state_append_audit_event(\$database, \$requestedBy, 'sync_' . \$kind . '_requested'");
$publishPosition=strpos($state,'rename($staging, $final)');
sync_assert($auditPosition!==false&&$publishPosition!==false&&$auditPosition<$publishPosition,'Durable audit intent must be persisted before a request becomes visible to the service.');
$cancelAuditPosition=strpos($state,"portal_state_append_audit_event(\$database, \$cancel_requested_by, 'sync_cancel_requested'");
$cancelPublishPosition=strpos($state,'file_put_contents($path, $value, LOCK_EX)');
sync_assert($cancelAuditPosition!==false&&$cancelPublishPosition!==false&&$cancelAuditPosition<$cancelPublishPosition,'Durable cancellation audit must be persisted before the cancel marker becomes visible to the service.');
if(!function_exists('portal_path_has_reparse_component')){function portal_path_has_reparse_component(string $path):bool{return false;}}
require_once dirname(__DIR__).'/app/admin_sync.php';
$valid=['schema_version'=>1,'request_id'=>str_repeat('a',32),'kind'=>'preview','direction'=>'drive_to_library','status'=>'preview_ready','preview_id'=>str_repeat('b',32),'requested_by'=>1,'metrics_recorded'=>true,'add_count'=>0,'update_count'=>0,'delete_count'=>0,'conflict_count'=>0,'access_denied_count'=>0,'updated_at'=>gmdate('c')];
sync_assert(portal_sync_normalize_result($valid)!==null,'Valid metrics must normalize.');
foreach(['add_count','update_count','delete_count','conflict_count','access_denied_count'] as $field){$bad=$valid;unset($bad[$field]);sync_assert(portal_sync_normalize_result($bad)===null,'Missing metric must fail closed: '.$field);$bad=$valid;$bad[$field]=-1;sync_assert(portal_sync_normalize_result($bad)===null,'Negative metric must fail closed: '.$field);$bad=$valid;$bad[$field]='0';sync_assert(portal_sync_normalize_result($bad)===null,'Non-integer metric must fail closed: '.$field);}
$now=gmdate('c');
$fixture=[
 ['request_id'=>str_repeat('1',32),'kind'=>'preview','status'=>'preview_ready','direction'=>'drive_to_library','preview_id'=>str_repeat('a',32),'requested_by'=>1,'metrics_recorded'=>true,'updated_at'=>'2026-09-19T08:00:00+00:00'],
 ['request_id'=>str_repeat('2',32),'kind'=>'preview','status'=>'preview_ready','direction'=>'drive_to_library','preview_id'=>str_repeat('b',32),'requested_by'=>1,'metrics_recorded'=>true,'updated_at'=>'2026-09-19T08:01:00+00:00'],
];
$chosen=portal_sync_operation_candidate($fixture,1,static fn(array $item):bool=>(string)$item['preview_id']===str_repeat('b',32));
sync_assert((string)($chosen['request_id']??'')===str_repeat('2',32),'Only the newest eligible preview may become the current operation.');
$fixture[]=['request_id'=>str_repeat('3',32),'kind'=>'execute','status'=>'running','direction'=>'drive_to_library','preview_id'=>str_repeat('b',32),'requested_by'=>1,'metrics_recorded'=>false,'updated_at'=>'2026-09-19T08:02:00+00:00'];
$chosen=portal_sync_operation_candidate($fixture,1,static fn(array $item):bool=>true);
sync_assert((string)($chosen['request_id']??'')===str_repeat('3',32),'A newer queued or running request must suppress older preview actions.');
$historyFixture=[];for($i=0;$i<12;$i++)$historyFixture[]=['request_id'=>str_pad(dechex($i+1),32,'0',STR_PAD_LEFT),'kind'=>'preview','status'=>'success','direction'=>'drive_to_library','preview_id'=>null,'requested_by'=>1,'metrics_recorded'=>true,'updated_at'=>sprintf('2026-09-19T%02d:00:00+00:00',$i)];
$query=portal_sync_history_query(['status'=>'success','direction'=>'drive_to_library','start_date'=>'2026-09-19','end_date'=>'2026-09-19','request_id_prefix'=>'000']);
$page=portal_sync_history_page($historyFixture,$query,null,5);
sync_assert(count($page['items'])===5&&(int)$page['total_count']===12&&$page['has_more']===true,'History must default to five filtered rows and expose more when older records exist.');
sync_assert((string)($page['next_cursor']['request_id']??'')===(string)$page['items'][4]['request_id'],'History more cursor must start strictly after the final visible row.');
$next=portal_sync_history_page($historyFixture,$query,$page['next_cursor'],5);
sync_assert(count($next['items'])===5&&(string)$next['items'][0]['request_id']!==(string)$page['items'][4]['request_id'],'History cursor must neither duplicate nor skip the next older row.');
sync_assert(str_contains($admin,"<details class=\"sync-history-query\"'.(\$isActive?' open':'').'><summary>查詢條件</summary>"),'Default history query controls must be collapsed behind an accessible details summary and open when filters are active.');

$invalid=portal_sync_history_query(['status'=>'unknown','direction'=>'../../x','start_date'=>'2026-99-99','end_date'=>'2026-01-01','request_id_prefix'=>'not-hex']);
sync_assert($invalid['status']===''&&$invalid['direction']===''&&$invalid['start_date']===''&&$invalid['end_date']===''&&$invalid['request_id_prefix']==='','Invalid history filters must fail closed to the safe default query.');
echo "ADMIN_SYNC_CONTROL_CONTRACT_OK\n";
