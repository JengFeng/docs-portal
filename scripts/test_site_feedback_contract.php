<?php
declare(strict_types=1);

function fail_test(string $message): never { fwrite(STDERR, "[FAIL] {$message}\n"); exit(1); }
function assert_true(bool $condition, string $message): void { if (!$condition) fail_test($message); }

$root = dirname(__DIR__);
$module = $root . '/app/site_feedback.php';
$migration = $root . '/database/016_site_feedback.sql';
$snapshotMigration = $root . '/database/017_site_feedback_snapshots.sql';
$snapshotReviewMigration = $root . '/database/018_site_feedback_snapshot_review.sql';
$snapshotImmutabilityMigration = $root . '/database/019_site_feedback_snapshot_immutability.sql';
$archiveMigration = $root . '/database/020_site_feedback_archiving.sql';
$index = (string) file_get_contents($root . '/index.php');
$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$webConfig = (string) file_get_contents($root . '/web.config');
$moduleSource = (string) file_get_contents($module);
$feedbackJs = (string) file_get_contents($root . '/assets/site-feedback.js');

assert_true(is_file($module), 'site feedback backend module must exist');
assert_true(is_file($migration), 'site feedback migration must exist');
assert_true(is_file($snapshotMigration), 'immutable feedback snapshot migration must exist');
assert_true(is_file($snapshotReviewMigration), 'snapshot sensitive-content review migration must exist');
assert_true(is_file($snapshotImmutabilityMigration), 'database-enforced snapshot immutability migration must exist');
assert_true(is_file($archiveMigration), 'feedback batch soft-archive migration must exist');
require_once $root . '/app/bootstrap.php';

$reader = ['user_id' => 7, 'role_code' => 'reader'];
$editor = ['user_id' => 8, 'role_code' => 'editor'];
$admin = ['user_id' => 9, 'role_code' => 'admin'];

assert_true(portal_feedback_target_action('?action=documents', $reader) === 'documents', 'documents must be an allowed same-origin review target');
assert_true(portal_feedback_embed_request_allowed(['action'=>'documents','feedback_embed'=>'1'],'GET'),'safe target must opt into same-origin feedback framing');
assert_true(portal_feedback_embed_actions()===['documents','visual_assets','view','presentation','image_review','admin','admin_document','document_version_compare'],'iframe server and browser must share one explicit read-only action allowlist');
assert_true(str_contains($moduleSource,"'embed_actions'=>portal_feedback_embed_actions()"),'feedback browser state must receive the same server allowlist');
assert_true(!portal_feedback_embed_request_allowed(['action'=>'feedback_approve','feedback_embed'=>'1'],'POST'),'mutation route must never opt into framing');
assert_true(!portal_feedback_embed_request_allowed(['action'=>'documents'],'GET'),'ordinary pages must retain DENY framing');
$signed=portal_feedback_sign_request(['schema_version'=>1,'dispatch_id'=>'11111111-1111-4111-8111-111111111111'],'test-queue-key-that-is-long-enough-1234');
assert_true(preg_match('/\A[0-9a-f]{64}\z/',$signed['queue_hmac'])===1,'queue request must carry a keyed authentication tag');
assert_true(portal_feedback_request_signature($signed,'test-queue-key-that-is-long-enough-1234')===$signed['queue_hmac'],'queue signature must verify canonical payload');
$tampered=$signed;$tampered['dispatch_id']='22222222-2222-4222-8222-222222222222';assert_true(portal_feedback_request_signature($tampered,'test-queue-key-that-is-long-enough-1234')!==$signed['queue_hmac'],'queue signature must fail after request tampering');
assert_true(portal_feedback_target_action('?action=visual_assets', $reader) === 'visual_assets', 'visual assets must be reviewable by reader');
try { portal_feedback_target_action('?action=staging', $editor); fail_test('retired staging target accepted'); }
catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode === 422, 'retired staging target must fail closed'); }
assert_true(portal_feedback_target_action('?action=admin', $admin) === 'admin', 'admin must be reviewable by admin');
foreach (['https://evil.example/', '//evil.example/x', '?action=logout', '?action=admin_user_update', '?action=feedback', '?action=documents#x', '../index.php?action=documents'] as $unsafe) {
    try { portal_feedback_target_action($unsafe, $reader); fail_test('unsafe target accepted: ' . $unsafe); }
    catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode >= 400, 'unsafe target must fail closed'); }
}
try { portal_feedback_target_action('?action=admin', $reader); fail_test('reader must not annotate admin route'); }
catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode === 403, 'reader admin target must be forbidden'); }

assert_true(portal_feedback_transition_allowed('draft', 'queued_analysis', false), 'owner may submit a draft');
assert_true(!portal_feedback_transition_allowed('queued_analysis', 'approved', false), 'non-admin may not approve');
assert_true(!portal_feedback_transition_allowed('awaiting_approval', 'approved', true), 'obsolete approval transition must stay disabled');
assert_true(portal_feedback_transition_allowed('awaiting_approval', 'completed', true), 'administrator may confirm externally completed work directly after analysis');
assert_true(portal_feedback_transition_allowed('approved', 'completed', true), 'admin may explicitly record a Discord-completed batch without creating an execution queue');
assert_true(!portal_feedback_transition_allowed('draft', 'completed', true), 'completion must not skip specification analysis');
assert_true(!portal_feedback_transition_allowed('approved','queued_execution',true),'website approval must not enter an autonomous execution state');
assert_true(!portal_feedback_transition_allowed('draft', 'executing', true), 'state machine must reject skipped states');
try { portal_feedback_request_payload(['public_id'=>'11111111-1111-4111-8111-111111111111','revision_number'=>1,'title'=>'x'], [], 'execution', '22222222-2222-4222-8222-222222222222'); fail_test('website path accepted an execution request payload'); }
catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode === 409, 'execution payloads must fail closed'); }
assert_true(!str_contains($moduleSource,"'queued_execution'")&&!str_contains($moduleSource,"'executing'")&&!str_contains($moduleSource,"'execution_failed'"),'website feedback module must not recognize execution states');
assert_true(str_contains($moduleSource,"if(\$kind!=='analysis')")&&str_contains($moduleSource,"d.dispatch_kind='analysis'"),'website queue and result import must be analysis-only');
$rendererStart=strpos($moduleSource,'function portal_render_site_feedback');$rendererEnd=strpos($moduleSource,'function portal_handle_feedback_batch_create',$rendererStart);$rendererSource=($rendererStart!==false&&$rendererEnd!==false)?substr($moduleSource,$rendererStart,$rendererEnd-$rendererStart):'';
assert_true($rendererSource!==''&&!str_contains($rendererSource,'portal_feedback_sync_results'),'GET page rendering must not reconcile or mutate feedback results');
assert_true(str_contains($rendererSource,"\$selectedId=portal_valid_public_id(\$selected)")&&str_contains($rendererSource,"portal_feedback_snapshot(\$database,\$user,\$selectedId)"),'an explicit authorized batch id must be resolved directly instead of depending on the TOP 50 history list');
assert_true(!str_contains($rendererSource,'in_array($selected,$listedIds,true)')&&!str_contains($rendererSource,'$listedIds=array_map'),'explicit batch loading must not be coupled to the filtered history list');
assert_true(str_contains($rendererSource,"\$archiveView=\$batch['archived_at']!==null"),'explicit batch archive state must select the matching active or archived history view');
assert_true(str_contains($moduleSource,'portal_feedback_read_json_body();')&&str_contains($feedbackJs,'self.api(self.state.urls.status,{batch_id:batchId})')&&str_contains($feedbackJs,"'X-CSRF-Token':this.state.csrf_token"),'result reconciliation must use CSRF-protected POST polling');

$normalized = portal_feedback_normalize_item([
    'target_url' => '?action=documents', 'annotation_type' => 'rectangle',
    'geometry' => ['x' => .8, 'y' => .7, 'width' => -.4, 'height' => -.2],
    'viewport' => ['width' => 390, 'height' => 844, 'scroll_x' => 0, 'scroll_y' => 120, 'dpr' => 3],
    'selector' => '#document-list > article:nth-of-type(2)', 'element_text' => '<script>alert(1)</script>',
    'page_fingerprint'=>str_repeat('a',64),'element_fingerprint'=>str_repeat('b',64),
    'instruction' => '請把這一列字體放大',
], $reader);
assert_true(abs($normalized['geometry']['x'] - .4) < .000001 && abs($normalized['geometry']['width'] - .4) < .000001, 'reverse drag geometry must normalize');
assert_true($normalized['viewport']['width'] === 390 && $normalized['viewport']['dpr'] === 3.0, 'viewport metadata must be preserved');
assert_true($normalized['element_text'] === '<script>alert(1)</script>', 'text must remain data for escaped rendering, not be rewritten as HTML');
try { portal_feedback_normalize_item(['target_url'=>'?action=documents','annotation_type'=>'rectangle','geometry'=>['x'=>.1,'y'=>.1,'width'=>.2,'height'=>.2],'viewport'=>['width'=>390,'height'=>844],'instruction'=>'x'], $reader); fail_test('unbound annotation accepted'); }
catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode === 422, 'page and element fingerprints must be required'); }
try { portal_feedback_normalize_item(['target_url'=>'?action=documents','annotation_type'=>'rectangle','geometry'=>['x'=>-0.1,'y'=>0,'width'=>.2,'height'=>.2],'viewport'=>['width'=>390,'height'=>844],'page_fingerprint'=>str_repeat('a',64),'element_fingerprint'=>str_repeat('b',64),'instruction'=>'x'], $reader); fail_test('out-of-range geometry accepted'); }
catch (PortalFeedbackHttpException $e) { assert_true($e->statusCode === 422, 'invalid geometry must be rejected'); }

assert_true(PORTAL_FEEDBACK_SNAPSHOT_JSON_MAX_BYTES<8388608&&((int)ceil(PORTAL_FEEDBACK_SNAPSHOT_MAX_BYTES/3)*4)+4096<=PORTAL_FEEDBACK_SNAPSHOT_JSON_MAX_BYTES,'snapshot request cap must fit the deployed PHP 8M post_max_size while leaving room for JSON/base64 overhead');
function feedback_png_chunk(string $type,string $data):string{return pack('N',strlen($data)).$type.$data.pack('H*',hash('crc32b',$type.$data));}
function feedback_test_png(int $width,int $height,int $bit=8): string {
    $rows=str_repeat("\0".str_repeat("\xff\xff\xff",$width),$height);
    return "\x89PNG\r\n\x1a\n".feedback_png_chunk('IHDR',pack('NNCCCCC',$width,$height,$bit,2,0,0,0)).feedback_png_chunk('IDAT',gzcompress($rows,9)).feedback_png_chunk('IEND','');
}
function feedback_test_malformed_idat_png(int $width,int $height):string{return "\x89PNG\r\n\x1a\n".feedback_png_chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).feedback_png_chunk('IDAT','not-a-zlib-stream').feedback_png_chunk('IEND','');}
function feedback_test_duplicate_ihdr_png(int $width,int $height):string{$ihdr=feedback_png_chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0));$rows=str_repeat("\0".str_repeat("\xff\xff\xff",$width),$height);return "\x89PNG\r\n\x1a\n".$ihdr.$ihdr.feedback_png_chunk('IDAT',gzcompress($rows,9)).feedback_png_chunk('IEND','');}
$snapshotPayload=portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844,'scroll_x'=>0,'scroll_y'=>120],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_png(390,844))],$reader);
assert_true($snapshotPayload['snapshot_kind']==='before'&&$snapshotPayload['image_width']===390&&$snapshotPayload['image_height']===844&&strlen($snapshotPayload['image_sha256'])===64,'valid bounded PNG snapshot must normalize with immutable hash and viewport binding');
assert_true(function_exists('portal_feedback_snapshot_binding_matches'),'snapshot idempotency must compare complete immutable binding metadata');
$storedBinding=['image_sha256'=>$snapshotPayload['image_sha256'],'target_url'=>$snapshotPayload['target_url'],'target_url_sha256'=>$snapshotPayload['target_url_sha256'],'mime_type'=>'image/png','viewport_width'=>$snapshotPayload['viewport_width'],'viewport_height'=>$snapshotPayload['viewport_height'],'scroll_x'=>$snapshotPayload['scroll_x'],'scroll_y'=>$snapshotPayload['scroll_y'],'image_width'=>$snapshotPayload['image_width'],'image_height'=>$snapshotPayload['image_height'],'image_byte_count'=>$snapshotPayload['image_byte_count'],'sensitive_content_reviewed'=>1,'redaction_profile'=>'password-explicit-v1'];
assert_true(portal_feedback_snapshot_binding_matches($storedBinding,$snapshotPayload),'exact immutable binding should be idempotent');$changedBinding=$storedBinding;$changedBinding['scroll_y']++;
assert_true(!portal_feedback_snapshot_binding_matches($changedBinding,$snapshotPayload),'same image bytes with different viewport/scroll binding must conflict');
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'image_base64'=>base64_encode(feedback_test_png(390,844))],$reader);fail_test('snapshot accepted without sensitive-content confirmation');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'snapshot save must require explicit sensitive-content review confirmation');}
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_png(391,844))],$reader);fail_test('mismatched snapshot dimensions accepted');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'snapshot dimensions must match bound viewport');}
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_png(390,844,1))],$reader);fail_test('invalid truecolor PNG bit depth accepted');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'PNG bit depth and color type combination must be valid');}
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_malformed_idat_png(390,844))],$reader);fail_test('malformed PNG IDAT accepted');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'PNG IDAT must decompress to exact scanline bytes');}
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'before','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_duplicate_ihdr_png(390,844))],$reader);fail_test('duplicate PNG IHDR accepted');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'PNG must contain exactly one leading IHDR');}
try{portal_feedback_validate_snapshot_payload(['snapshot_kind'=>'execution','target_url'=>'?action=documents','revision_number'=>2,'viewport'=>['width'=>390,'height'=>844],'sensitive_content_reviewed'=>true,'image_base64'=>base64_encode(feedback_test_png(390,844))],$reader);fail_test('unsafe snapshot kind accepted');}
catch(PortalFeedbackHttpException $e){assert_true($e->statusCode===422,'snapshot kind must fail closed');}

$sql = (string) file_get_contents($migration);
$snapshotSql=(string)file_get_contents($snapshotMigration);
$snapshotReviewSql=(string)file_get_contents($snapshotReviewMigration);
$snapshotImmutabilitySql=(string)file_get_contents($snapshotImmutabilityMigration);
$archiveSql=(string)file_get_contents($archiveMigration);
assert_true(str_contains($sql, 'SET QUOTED_IDENTIFIER ON;') && str_contains($sql, 'SET ANSI_NULLS ON;'), 'migration must enable SQL Server SET options required by filtered indexes');
$permissions = (string) file_get_contents($root . '/database/004_app_permissions.template.sql');
foreach (['site_feedback_batches','site_feedback_items','site_feedback_dispatches','site_feedback_events','CK_site_feedback_batches_status','CK_site_feedback_items_geometry','UX_site_feedback_items_batch_sequence_active'] as $token) {
    assert_true(str_contains($sql, $token), 'migration missing contract token: ' . $token);
}
foreach(['site_feedback_snapshots','snapshot_kind','target_url_sha256','image_sha256','image_bytes','UQ_site_feedback_snapshots_immutable','DENY UPDATE, DELETE'] as $token){assert_true(str_contains($snapshotSql,$token),'snapshot migration missing contract token: '.$token);}
foreach(['sensitive_content_reviewed','redaction_profile','CK_site_feedback_snapshots_review'] as $token){assert_true(str_contains($snapshotReviewSql,$token),'snapshot review migration missing contract token: '.$token);}
foreach(['TR_site_feedback_snapshots_immutable','INSTEAD OF UPDATE, DELETE','THROW 51020'] as $token){assert_true(str_contains($snapshotImmutabilitySql,$token),'snapshot immutability migration missing contract token: '.$token);}
foreach(['archived_at','archived_by_user_id','FK_site_feedback_batches_archived_by','IX_site_feedback_batches_archive_updated','020_site_feedback_archiving.sql'] as $token){assert_true(str_contains($archiveSql,$token),'feedback archive migration missing contract token: '.$token);}
foreach (['site_feedback_batches','site_feedback_items','site_feedback_dispatches','site_feedback_events','DENY DELETE'] as $token) {
    assert_true(str_contains($permissions, $token), 'least-privilege template missing token: ' . $token);
}
assert_true(str_contains($permissions,'site_feedback_snapshots')&&str_contains($permissions,'DENY UPDATE, DELETE ON dbo.site_feedback_snapshots'),'snapshot bytes must be append-only for the portal identity');
foreach (['feedback','feedback_batch_create','feedback_item_save','feedback_item_delete','feedback_submit','feedback_status','feedback_approve','feedback_reject','feedback_complete','feedback_batch_archive','feedback_batch_restore','feedback_snapshot_save','feedback_snapshot_image'] as $route) {
    assert_true(str_contains($index, "'{$route}'"), 'route missing from allowlist: ' . $route);
}
assert_true(str_contains($moduleSource,'portal_feedback_save_page_snapshot')&&str_contains($moduleSource,'portal_handle_feedback_snapshot_image'),'feedback snapshot save and authenticated private image streaming handlers are required');
assert_true(str_contains($moduleSource,'function portal_feedback_complete(')
    && str_contains($moduleSource,"status_code='completed'")
    && str_contains($moduleSource,'feedback_batch_completed_external')
    && str_contains($moduleSource,'execution_summary=?')
    && str_contains($moduleSource,'portal_handle_feedback_complete'), 'admin completion must be an explicit audited POST record of externally completed work');
$completeStart=strpos($moduleSource,'function portal_feedback_complete(');$completeEnd=strpos($moduleSource,'function portal_render_site_feedback',$completeStart);$completeSource=($completeStart!==false&&$completeEnd!==false)?substr($moduleSource,$completeStart,$completeEnd-$completeStart):'';
assert_true($completeSource!==''&&!str_contains($completeSource,'portal_feedback_publish_request')&&!str_contains($completeSource,"'execution'"),'completion acknowledgement must never dispatch or import autonomous website execution');
assert_true(str_contains($moduleSource,'function portal_feedback_set_archived')&&str_contains($moduleSource,"['draft','awaiting_approval','approved','completed','analysis_failed','rejected']")&&str_contains($moduleSource,'feedback_batch_archived')&&str_contains($moduleSource,'feedback_batch_restored'),'archive must be a reversible audited soft delete limited to stable batch states');
assert_true(str_contains($moduleSource,'function portal_feedback_assert_batch_active')&&substr_count($moduleSource,'portal_feedback_assert_batch_active($batch)')>=5,'every feedback mutation path must reject archived batches');
assert_true(str_contains($moduleSource,'b.archived_at IS NULL')&&str_contains($moduleSource,'b.archived_at IS NOT NULL')&&str_contains($moduleSource,'portal_feedback_archive_counts'),'normal and archived history views must be separate and counted server-side');
assert_true(str_contains($moduleSource,'portal_feedback_attach_list_snapshots')&&str_contains($moduleSource,"\$row['snapshots']=[]")&&str_contains($moduleSource,'WHERE s.batch_id IN (')&&str_contains($moduleSource,"\$row['snapshots']=array_values"),'history list must attach private immutable snapshot metadata in one bounded batch query instead of reconstructing live pages or issuing per-row queries');
assert_true(str_contains($moduleSource,"COL_LENGTH(N'dbo.site_feedback_batches', N'archived_at')")&&str_contains($moduleSource,"COL_LENGTH(N'dbo.site_feedback_batches', N'archived_by_user_id')"),'schema availability must fail closed until archive migration columns exist');
assert_true(str_contains($moduleSource,"s.snapshot_kind='before'")&&str_contains($moduleSource,'s.revision_number=?'),'optional immutable before snapshots must remain revision-bound when present');
$submitStart=strpos($moduleSource,'function portal_feedback_submit(');$submitEnd=strpos($moduleSource,'function portal_feedback_complete(');$submitSource=substr($moduleSource,$submitStart,$submitEnd-$submitStart);
assert_true(!str_contains($submitSource,'portal_feedback_missing_before_targets'),'optional before snapshots must not block specification analysis');
assert_true(!str_contains($submitSource,'INSERT INTO dbo.site_feedback_snapshots')&&!str_contains($submitSource,'revision_number=revision_number+1'),'analysis retry must retain the same revision when requirements are unchanged and must never clone a prior capture into a new revision');
$vendorScript=$root.'/assets/vendor/html2canvas/html2canvas.min.js';assert_true(str_contains($bootstrap,'html2canvas.min.js')&&is_file($vendorScript)&&hash_file('sha256',$vendorScript)==='e87e550794322e574a1fda0c1549a3c70dae5a93d9113417a429016838eab8cb','pinned same-origin html2canvas 1.4.1 asset must match the reviewed SHA-256 and load only with feedback assets');
assert_true(str_contains($bootstrap, "if (portal_is_admin(\$user))") && str_contains($bootstrap, "portal_url('feedback')") && str_contains($bootstrap, 'site-feedback-shortcut') && str_contains($bootstrap, 'aria-label="畫面修改需求"'), 'administrator header must expose the visual requirements shortcut while non-admin rendering leaves it empty');
assert_true(str_contains($bootstrap, 'site-feedback.css') && str_contains($bootstrap, 'site-feedback.js'), 'dedicated feedback assets must be loaded only by its page scope');
assert_true(str_contains($bootstrap,"frame-ancestors 'self'")&&str_contains($index,'portal_feedback_embed_request_allowed'),'only explicit safe feedback embeds may receive SAMEORIGIN framing');
assert_true(!preg_match('/<add\s+name="X-Frame-Options"/i',$webConfig),'IIS must not override the PHP per-route frame policy');
assert_true(str_contains((string)file_get_contents($module),'MAXRECURSION 200'),'parent reassignment must reject indirect issue-tree cycles');
assert_true(str_contains((string)file_get_contents($module),'snapshots_optional'),'analysis retry must explicitly preserve optional snapshot semantics');
assert_true(!str_contains((string)file_get_contents($module),'function portal_feedback_approve(')&&!str_contains((string)file_get_contents($module),"portal_feedback_publish_request(\$config,'execution'")&&str_contains((string)file_get_contents($module),"status_code IN ('awaiting_approval','approved')"),'simplified workflow must remove approval and remain analysis-only with explicit external completion');
assert_true(str_contains((string)file_get_contents($module),'portal_feedback_reconcile_analysis_request')&&str_contains((string)file_get_contents($module),'RECONCILE_HASH_MISMATCH')&&str_contains((string)file_get_contents($module),".'.processing'"),'queued analysis must recognize a live worker claim, reconstruct only a missing queue file, or fail closed on hash mismatch');
assert_true(str_contains((string)file_get_contents($module),'sequence_candidates')&&!str_contains($sql,'UQ_site_feedback_items_batch_sequence'),'active issue ordering must allocate the lowest free slot after soft deletion');
assert_true(str_contains((string)file_get_contents($module),'ORDER BY i.item_id')&&!str_contains((string)file_get_contents($module),'ORDER BY i.sequence_number,i.item_id'),'reload order must follow immutable creation identity so reused sequence gaps cannot renumber older number markers');

echo "[OK] site feedback route, RBAC, state, geometry, schema, and asset contracts passed.\n";
