<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
function result_fail(string $m): never{fwrite(STDERR,"[FAIL] {$m}\n");exit(1);}function result_ok(bool $v,string $m):void{if(!$v)result_fail($m);}
$dispatch=['dispatch_id'=>'11111111-1111-4111-8111-111111111111','batch_public_id'=>'22222222-2222-4222-8222-222222222222','revision_number'=>3,'request_sha256'=>str_repeat('a',64),'dispatch_kind'=>'analysis'];
$items=[['public_id'=>'33333333-3333-4333-8333-333333333333'],['public_id'=>'44444444-4444-4444-8444-444444444444']];
$result=['schema_version'=>1,'dispatch_id'=>$dispatch['dispatch_id'],'dispatch_kind'=>'analysis','batch_id'=>$dispatch['batch_public_id'],'revision'=>3,'request_sha256'=>str_repeat('a',64),'summary'=>'整批統一放大操作文字並維持手機版。','items'=>[
 ['item_id'=>$items[0]['public_id'],'structured_title'=>'放大文件卡文字','structured_requirement'=>'將文件卡主要文字調整為至少 18px。','acceptance_criteria'=>['桌面及 390px 均可讀','無整頁水平溢位'],'risk_level'=>'low'],
 ['item_id'=>$items[1]['public_id'],'structured_title'=>'縮減桌面留白','structured_requirement'=>'工作區使用近滿版寬度。','acceptance_criteria'=>['1920px 內容寬度不小於 1700px'],'risk_level'=>'medium']
]];
$validated=portal_feedback_validate_result($result,$dispatch,$items);
result_ok($validated['summary']===$result['summary']&&count($validated['items'])===2,'valid analysis result must normalize');
foreach ([
 ['request_sha256',str_repeat('b',64)],['revision',4],['dispatch_kind','execution'],['batch_id','55555555-5555-4555-8555-555555555555']
] as [$key,$value]) { $bad=$result;$bad[$key]=$value;try{portal_feedback_validate_result($bad,$dispatch,$items);result_fail('binding mismatch accepted: '.$key);}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'binding mismatch must conflict');} }
$missing=$result;array_pop($missing['items']);try{portal_feedback_validate_result($missing,$dispatch,$items);result_fail('missing item accepted');}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'missing item set must conflict');}
$duplicate=$result;$duplicate['items'][1]['item_id']=$items[0]['public_id'];try{portal_feedback_validate_result($duplicate,$dispatch,$items);result_fail('duplicate item accepted');}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'duplicate item must conflict');}
$tooLarge=$result;$tooLarge['items'][0]['acceptance_criteria']=array_fill(0,20,str_repeat('驗',500));try{portal_feedback_validate_result($tooLarge,$dispatch,$items);result_fail('oversized acceptance JSON accepted');}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'acceptance JSON must fit the SQL column before import');}
$fullItem=['public_id'=>'66666666-6666-4666-8666-666666666666','parent_public_id'=>null,'sequence_number'=>3,'target_url'=>'?action=documents','target_action'=>'documents','page_fingerprint'=>null,'viewport_width'=>1200,'viewport_height'=>800,'scroll_x'=>0,'scroll_y'=>0,'device_pixel_ratio'=>1,'annotation_type_code'=>'rectangle','geometry'=>['x'=>.1,'y'=>.1,'width'=>.2,'height'=>.2],'element_selector'=>'#x','element_tag'=>'section','element_text'=>'UNTRUSTED RAW PAGE TEXT','element_fingerprint'=>null,'instruction_text'=>'UNTRUSTED RAW USER PROMPT','structured_title'=>'精確標題','structured_requirement'=>'精確需求','acceptance_criteria'=>['驗收'],'risk_level'=>'low'];
$workerFailure=['schema_version'=>1,'dispatch_id'=>$dispatch['dispatch_id'],'dispatch_kind'=>'analysis','batch_id'=>$dispatch['batch_public_id'],'revision'=>3,'request_sha256'=>str_repeat('a',64),'status'=>'failed','summary'=>'Worker failed closed.','error_code'=>'TimeoutExpired'];
$failureValidated=portal_feedback_validate_result($workerFailure,$dispatch,$items);result_ok($failureValidated['status']==='failed'&&$failureValidated['error_code']==='TimeoutExpired','bound worker failure must import as terminal failure rather than binding mismatch');
$analysisPayload=portal_feedback_request_payload(['public_id'=>$dispatch['batch_public_id'],'revision_number'=>3,'title'=>'測試'],[$fullItem],'analysis',$dispatch['dispatch_id']);
result_ok(str_contains(json_encode($analysisPayload,JSON_UNESCAPED_UNICODE),'UNTRUSTED RAW USER PROMPT'),'analysis must include original user intent');
try{portal_feedback_request_payload(['public_id'=>$dispatch['batch_public_id'],'revision_number'=>3,'title'=>'測試'],[$fullItem],'execution',$dispatch['dispatch_id']);result_fail('website execution request payload accepted');}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'website execution request payload must fail closed');}
$execution=$dispatch;$execution['dispatch_kind']='execution';$executionResult=['schema_version'=>1,'dispatch_id'=>$dispatch['dispatch_id'],'dispatch_kind'=>'execution','batch_id'=>$dispatch['batch_public_id'],'revision'=>3,'request_sha256'=>str_repeat('a',64),'status'=>'completed','summary'=>'不應匯入','changed_files'=>['assets/app.css'],'verification'=>['test_x: OK']];
try{portal_feedback_validate_result($executionResult,$execution,$items);result_fail('website execution result accepted');}catch(PortalFeedbackHttpException $e){result_ok($e->statusCode===409,'website execution result must fail closed');}
echo "[OK] site feedback Hermes result binding and exact-item-set contracts passed.\n";
