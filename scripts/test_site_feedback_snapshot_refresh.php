<?php
declare(strict_types=1);
function snapshot_refresh_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$js=(string)file_get_contents(dirname(__DIR__).'/assets/site-feedback.js');
$syncStart=strpos($js,'FeedbackWorkspace.prototype.syncBatchSummary');$syncEnd=strpos($js,'FeedbackWorkspace.prototype.renderAll',$syncStart);$sync=$syncStart!==false&&$syncEnd!==false?substr($js,$syncStart,$syncEnd-$syncStart):'';
snapshot_refresh_assert(str_contains($sync,'listed.snapshots=clone(this.batch.snapshots||[])'),'current batch snapshot metadata must synchronize into the history-list record after every server batch response');
$historyStart=strpos($js,'FeedbackWorkspace.prototype.openHistorySnapshot');$historyEnd=strpos($js,'FeedbackWorkspace.prototype.describeRelativePosition',$historyStart);$history=$historyStart!==false&&$historyEnd!==false?substr($js,$historyStart,$historyEnd-$historyStart):'';
snapshot_refresh_assert(str_contains($history,'this.batch')&&str_contains($history,'String(this.batch.public_id)===String(batchId)')&&str_contains($history,'batch=this.batch'),'history screenshot opening must prefer the authoritative current batch over a stale history-list object');
snapshot_refresh_assert(str_contains($js,'FeedbackWorkspace.prototype.currentSnapshotTarget')&&str_contains($js,'targets.includes(currentTarget)?currentTarget:(targets[0]')&&str_contains($js,'currentTarget=this.currentSnapshotTarget()'),'snapshot cards must fall back to the selected batch target when the iframe target is temporarily unavailable after workflow transitions');
echo "[OK] feedback snapshot state refresh contracts passed.\n";
