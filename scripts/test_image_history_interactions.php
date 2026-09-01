<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=(string)file_get_contents($root.'/app/image_library.php');
$js=(string)file_get_contents($root.'/assets/image-library.js');
$css=(string)file_get_contents($root.'/assets/site-feedback.css');
function history_check(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] $message\n");exit(1);}}
history_check(str_contains($php,'data-image-revision-history-status'),'history dialog must contain its own live feedback region');
history_check(str_contains($js,"dataset.imageHistoryAction='select'")&&str_contains($js,'選取此 Revision'),'history rows must provide an explicit Revision selection action');
history_check(str_contains($js,"dataset.imageUnavailable='1'")&&str_contains($js,"button.dataset.imageUnavailable==='1'"),'unavailable snapshot actions must remain disabled after workflow re-projection');
history_check(str_contains($js,'無修改前截圖')&&str_contains($js,'無修改後截圖'),'unavailable screenshot controls must say they are unavailable');
history_check(str_contains($js,'setHistoryFeedback')&&str_contains($js,'已複製 Revision ID'),'copy ID must report success inside the dialog');
history_check(str_contains($js,'封存中…')&&str_contains($js,'解除封存中…')&&str_contains($js,'封存狀態更新失敗'),'archive actions must expose busy and error feedback inside the dialog');
history_check(str_contains($js,"completeRevision.textContent=flow.completionLocked?'已完成並鎖定':(canComplete?'確認修改已完成':'先選擇要完成的 Revision')"),'completion action must provide an actionable no-selection label');
history_check(str_contains($js,'historyMutationBusy=false')&&str_contains($js,'revisionBusy:revisionBusy||historyMutationBusy')&&str_contains($js,'historyMutationBusy=true;applyWorkflowState()'),'archive requests must share the full workflow busy lock and release it on every terminal path');
history_check(str_contains($js,"if(!revisionCollaboration.canComplete(revisionState)){openRevisionGuidance();return;}"),'completion click must open explicit Revision-selection guidance without bypassing eligibility');
history_check(str_contains($css,'.site-feedback-history-feedback')&&str_contains($css,'.site-feedback-batch-row-actions button:disabled'),'dialog feedback and unavailable actions need visible styling');
echo "[OK] IMAGE REVIEW completion and history controls have explicit actions and in-dialog feedback.\n";
