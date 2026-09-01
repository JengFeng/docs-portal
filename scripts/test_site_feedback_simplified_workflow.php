<?php
declare(strict_types=1);
function fail_simple(string $message): never { fwrite(STDERR,"[FAIL] {$message}\n"); exit(1); }
function simple_check(bool $ok,string $message): void { if(!$ok) fail_simple($message); }
$root=dirname(__DIR__);$index=(string)file_get_contents($root.'/index.php');$bootstrap=(string)file_get_contents($root.'/app/bootstrap.php');$php=(string)file_get_contents($root.'/app/site_feedback.php');$js=(string)file_get_contents($root.'/assets/site-feedback.js');require_once $root.'/app/bootstrap.php';

simple_check(str_contains($bootstrap,"if (portal_is_admin(\$user))")&&preg_match('/if \(portal_is_admin\(\$user\)\) \{[\s\S]*site-feedback-shortcut[\s\S]*\}/',$bootstrap)===1,'feedback shortcut must be rendered only inside the administrator role branch');
simple_check(str_contains($index,'$feedbackActions')&&str_contains($index,'portal_require_admin($database)'),'every feedback page/API/image route must be guarded by the administrator role at the dispatcher');
simple_check(!str_contains($index,"if (\$action === 'feedback_approve')")&&!str_contains($index,"if (\$action === 'feedback_reject')"),'approval and rejection dispatch handlers must be retired');
simple_check(!str_contains($php,"'approve'=>portal_url('feedback_approve')")&&!str_contains($php,"'reject'=>portal_url('feedback_reject')"),'browser state must not expose obsolete approval routes');
simple_check(!str_contains($php,'只能送出自己建立的批次')&&!str_contains($php,'只能修改自己建立的草稿')&&!str_contains($php,'只能刪除自己草稿'),'administrator collaboration must not be limited to the batch creator');
simple_check(portal_feedback_transition_allowed('awaiting_approval','completed',true),'analysis-ready batches must complete without an approval state');
simple_check(!portal_feedback_transition_allowed('awaiting_approval','approved',true),'obsolete approval transition must be disabled');
$submitStart=strpos($php,'function portal_feedback_submit(');$submitEnd=strpos($php,'function portal_feedback_complete(');$submit=substr($php,$submitStart,$submitEnd-$submitStart);
simple_check(!str_contains($submit,'portal_feedback_missing_before_targets'),'before snapshots must be optional and must not block specification analysis');
simple_check(str_contains($php,"status_code IN ('awaiting_approval','approved')"),'completion must accept the new analysis-ready state and legacy approved records');
simple_check(!str_contains($js,"dataset:{action:'approve'}")&&!str_contains($js,"dataset:{action:'reject'}")&&!str_contains($js,'approveBatch'),'browser UI must remove approval and rejection controls');
simple_check(str_contains($js,"submitButton.textContent='整理精確規格'")&&str_contains($js,"dataset:{action:'submit'}"),'current right panel must expose one-click specification analysis');
simple_check(str_contains($js,"['awaiting_approval','approved','completed'].includes(this.batch?.status_code||'')"),'copy and JSON must unlock only after analysis completes');
simple_check(str_contains($js,"['awaiting_approval','approved'].includes(this.batch?.status_code||'')")&&str_contains($js,"text:'確認修改已完成'"),'analysis-ready and legacy-approved records must expose completion confirmation');
simple_check(!str_contains($js,'site-feedback-batch-workflow-actions'),'history rows must be view/switch/history only, with no workflow buttons');
simple_check(str_contains($js,"draft: '編輯中'")&&str_contains($js,"awaiting_approval: '規格已整理，可直接協作'")&&!str_contains($js,'規格已整理，請 Gary 核准'),'user-facing workflow labels must remove draft and approval language');
simple_check(str_contains($js,"text:'修改前畫面截圖'")&&str_contains($js,"text:'修改後結果截圖'")&&!str_contains($js,"text:'修改前內容'")&&!str_contains($js,"text:'調整前草稿'"),'history labels must name the exact immutable before/after screenshots rather than content or draft/final views');
echo "[OK] simplified administrator-only feedback workflow contract passed.\n";
