<?php
declare(strict_types=1);

const PORTAL_FEEDBACK_JSON_MAX_BYTES = 262144;
const PORTAL_FEEDBACK_SNAPSHOT_JSON_MAX_BYTES = 7500000;
const PORTAL_FEEDBACK_SNAPSHOT_MAX_BYTES = 5500000;
const PORTAL_FEEDBACK_SNAPSHOT_MAX_PIXELS = 12000000;
const PORTAL_FEEDBACK_MAX_ITEMS = 200;

final class PortalFeedbackHttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode)
    {
        parent::__construct($message);
    }
}

function portal_feedback_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function portal_feedback_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function portal_feedback_json_action(callable $action): never
{
    try {
        $result = $action();
        portal_feedback_json_response(is_array($result) ? $result : ['ok' => true]);
    } catch (PortalFeedbackHttpException $exception) {
        portal_feedback_json_response(['ok' => false, 'error' => $exception->getMessage()], $exception->statusCode);
    } catch (Throwable $exception) {
        error_log('TWWATER site feedback action failed: ' . $exception->getMessage());
        portal_feedback_json_response(['ok' => false, 'error' => '視覺化修改需求功能暫時無法使用。'], 500);
    }
}

function portal_feedback_read_json_body(): array
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new PortalFeedbackHttpException('此操作只接受 POST。', 405);
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 0 || $length > PORTAL_FEEDBACK_JSON_MAX_BYTES) {
        throw new PortalFeedbackHttpException('送出的需求資料過大。', 413);
    }
    $raw = (string) file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > PORTAL_FEEDBACK_JSON_MAX_BYTES) {
        throw new PortalFeedbackHttpException('送出的需求資料不完整。', 400);
    }
    try {
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new PortalFeedbackHttpException('送出的需求資料格式不正確。', 400);
    }
    if (!is_array($payload)) {
        throw new PortalFeedbackHttpException('送出的需求資料格式不正確。', 400);
    }
    $provided = (string) ($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $expected = portal_csrf_token();
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        throw new PortalFeedbackHttpException('安全驗證失敗，請重新整理後再操作。', 403);
    }
    return $payload;
}

function portal_feedback_read_snapshot_json_body(): array
{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')throw new PortalFeedbackHttpException('此操作只接受 POST。',405);
    $length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length<0||$length>PORTAL_FEEDBACK_SNAPSHOT_JSON_MAX_BYTES)throw new PortalFeedbackHttpException('截圖資料過大。',413);
    $raw=(string)file_get_contents('php://input');if($raw===''||strlen($raw)>PORTAL_FEEDBACK_SNAPSHOT_JSON_MAX_BYTES)throw new PortalFeedbackHttpException('截圖資料不完整。',400);
    try{$payload=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PortalFeedbackHttpException('截圖資料格式不正確。',400);}
    if(!is_array($payload))throw new PortalFeedbackHttpException('截圖資料格式不正確。',400);
    $provided=(string)($payload['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));$expected=portal_csrf_token();
    if($provided===''||$expected===''||!hash_equals($expected,$provided))throw new PortalFeedbackHttpException('安全驗證失敗，請重新整理後再操作。',403);
    return $payload;
}

function portal_feedback_snapshot_schema_available(PDO $database): bool
{
    return (int)$database->query("SELECT CASE WHEN OBJECT_ID(N'dbo.site_feedback_snapshots',N'U') IS NOT NULL THEN 1 ELSE 0 END")->fetchColumn()===1;
}

function portal_feedback_validate_png(string $bytes,int $expectedWidth,int $expectedHeight): array
{
    $length=strlen($bytes);if($length<67||$length>PORTAL_FEEDBACK_SNAPSHOT_MAX_BYTES||substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n")throw new PortalFeedbackHttpException('截圖必須是有效且大小受限的 PNG。',422);
    $offset=8;$seenHeader=false;$seenData=false;$seenEnd=false;$seenPalette=false;$idatEnded=false;$width=0;$height=0;$bit=0;$color=0;$idat='';$chunkIndex=0;
    while($offset+12<=$length){
        $chunkLength=unpack('Nvalue',substr($bytes,$offset,4))['value'];$offset+=4;
        if($chunkLength>PORTAL_FEEDBACK_SNAPSHOT_MAX_BYTES||$offset+8+$chunkLength>$length)throw new PortalFeedbackHttpException('PNG 截圖結構不完整。',422);
        $type=substr($bytes,$offset,4);$offset+=4;$data=substr($bytes,$offset,$chunkLength);$offset+=$chunkLength;$crc=substr($bytes,$offset,4);$offset+=4;
        if(preg_match('/\A[A-Za-z]{4}\z/',$type)!==1||!ctype_upper($type[2]))throw new PortalFeedbackHttpException('PNG chunk 類型不正確。',422);
        $actualCrc=pack('H*',hash('crc32b',$type.$data));if(!hash_equals($actualCrc,$crc))throw new PortalFeedbackHttpException('PNG 截圖校驗失敗。',422);
        if($type==='IHDR'){
            if($seenHeader||$chunkIndex!==0||$chunkLength!==13)throw new PortalFeedbackHttpException('PNG 必須只有一個起始 IHDR。',422);
            $header=unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace',$data);$width=(int)$header['width'];$height=(int)$header['height'];$bit=(int)$header['bit'];$color=(int)$header['color'];$validDepths=[0=>[1,2,4,8,16],2=>[8,16],3=>[1,2,4,8],4=>[8,16],6=>[8,16]];
            if($width<240||$height<240||$width>8192||$height>8192||$width*$height>PORTAL_FEEDBACK_SNAPSHOT_MAX_PIXELS||!isset($validDepths[$color])||!in_array($bit,$validDepths[$color],true)||$header['compression']!==0||$header['filter']!==0||$header['interlace']!==0)throw new PortalFeedbackHttpException('PNG 截圖尺寸或格式不受支援。',422);$seenHeader=true;$chunkIndex++;continue;
        }
        if(!$seenHeader||$type==='IHDR')throw new PortalFeedbackHttpException('PNG 截圖缺少唯一標頭。',422);
        if($type==='IDAT'){
            if($idatEnded)throw new PortalFeedbackHttpException('PNG IDAT 必須連續。',422);$seenData=true;$idat.=$data;if(strlen($idat)>PORTAL_FEEDBACK_SNAPSHOT_MAX_BYTES)throw new PortalFeedbackHttpException('PNG 壓縮資料過大。',422);
        }else{
            if($seenData)$idatEnded=true;
            if($type==='PLTE'){
                if($seenPalette||$seenData||$chunkLength<3||$chunkLength>768||$chunkLength%3!==0)throw new PortalFeedbackHttpException('PNG 色盤不正確。',422);$seenPalette=true;
            }elseif($type==='IEND'){
                if($chunkLength!==0||$offset!==$length)throw new PortalFeedbackHttpException('PNG 截圖結尾不正確。',422);$seenEnd=true;break;
            }elseif(ctype_upper($type[0]))throw new PortalFeedbackHttpException('PNG 包含不支援的必要 chunk。',422);
        }
        $chunkIndex++;
    }
    if(!$seenHeader||!$seenData||!$seenEnd||($color===3&&!$seenPalette)||$width!==$expectedWidth||$height!==$expectedHeight)throw new PortalFeedbackHttpException('PNG 截圖尺寸與儲存的可視區不一致。',422);
    $samples=[0=>1,2=>3,3=>1,4=>2,6=>4][$color];$rowBytes=intdiv($width*$samples*$bit+7,8);$rawLength=($rowBytes+1)*$height;
    if($rawLength<1||$rawLength>60000000)throw new PortalFeedbackHttpException('PNG 解壓後資料過大。',422);
    $raw=@gzuncompress($idat,$rawLength);if($raw===false||strlen($raw)!==$rawLength)throw new PortalFeedbackHttpException('PNG IDAT 無法解壓成宣告的像素資料。',422);
    for($row=0,$stride=$rowBytes+1;$row<$height;$row++){if(ord($raw[$row*$stride])>4)throw new PortalFeedbackHttpException('PNG scanline filter 不正確。',422);}unset($raw,$idat);
    return ['width'=>$width,'height'=>$height,'byte_count'=>$length,'sha256'=>hash('sha256',$bytes)];
}

function portal_feedback_validate_snapshot_payload(array $payload,array $user): array
{
    $kind=is_string($payload['snapshot_kind']??null)?(string)$payload['snapshot_kind']:'';if(!in_array($kind,['before','after'],true))throw new PortalFeedbackHttpException('截圖種類不正確。',422);
    $revision=filter_var($payload['revision_number']??null,FILTER_VALIDATE_INT);if($revision===false||$revision<1||$revision>1000000)throw new PortalFeedbackHttpException('截圖 Revision 不正確。',422);
    $targetUrl=trim(is_string($payload['target_url']??null)?(string)$payload['target_url']:'');$targetAction=portal_feedback_target_action($targetUrl,$user);
    $viewport=is_array($payload['viewport']??null)?$payload['viewport']:[];$width=filter_var($viewport['width']??null,FILTER_VALIDATE_INT);$height=filter_var($viewport['height']??null,FILTER_VALIDATE_INT);$scrollX=filter_var($viewport['scroll_x']??0,FILTER_VALIDATE_INT);$scrollY=filter_var($viewport['scroll_y']??0,FILTER_VALIDATE_INT);
    if($width===false||$height===false||$width<240||$height<240||$width>8192||$height>8192||$width*$height>PORTAL_FEEDBACK_SNAPSHOT_MAX_PIXELS||$scrollX===false||$scrollY===false||$scrollX<0||$scrollY<0||$scrollX>100000000||$scrollY>100000000)throw new PortalFeedbackHttpException('截圖可視區資料不正確。',422);
    if(($payload['sensitive_content_reviewed']??null)!==true)throw new PortalFeedbackHttpException('請先確認截圖未包含密碼、驗證碼、token 或未遮蔽敏感內容。',422);
    $encoded=is_string($payload['image_base64']??null)?(string)$payload['image_base64']:'';if($encoded===''||strlen($encoded)>7400000||preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/',$encoded)!==1)throw new PortalFeedbackHttpException('截圖內容不正確。',422);
    $bytes=base64_decode($encoded,true);if($bytes===false)throw new PortalFeedbackHttpException('截圖內容不正確。',422);$image=portal_feedback_validate_png($bytes,(int)$width,(int)$height);
    return ['snapshot_kind'=>$kind,'revision_number'=>(int)$revision,'target_url'=>$targetUrl,'target_action'=>$targetAction,'target_url_sha256'=>hash('sha256',$targetUrl),'viewport_width'=>(int)$width,'viewport_height'=>(int)$height,'scroll_x'=>(int)$scrollX,'scroll_y'=>(int)$scrollY,'image_width'=>$image['width'],'image_height'=>$image['height'],'image_byte_count'=>$image['byte_count'],'image_sha256'=>$image['sha256'],'sensitive_content_reviewed'=>true,'redaction_profile'=>'password-explicit-v1','image_bytes'=>$bytes];
}

function portal_feedback_snapshot_binding_matches(array $stored,array $submitted): bool
{
    if(!is_string($stored['image_sha256']??null)||!hash_equals(strtolower((string)$stored['image_sha256']),strtolower((string)($submitted['image_sha256']??''))))return false;
    foreach(['target_url','target_url_sha256'] as $field){if((string)($stored[$field]??'')!==(string)($submitted[$field]??''))return false;}
    if((string)($stored['mime_type']??'')!=='image/png'||(string)($stored['redaction_profile']??'')!=='password-explicit-v1'||(int)($stored['sensitive_content_reviewed']??0)!==1)return false;
    foreach(['viewport_width','viewport_height','scroll_x','scroll_y','image_width','image_height','image_byte_count'] as $field){if((int)($stored[$field]??-1)!==(int)($submitted[$field]??-2))return false;}
    return true;
}

function portal_feedback_schema_available(PDO $database): bool
{
    $statement = $database->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.site_feedback_batches', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.site_feedback_items', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.site_feedback_dispatches', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.site_feedback_events', N'U') IS NOT NULL
                       AND COL_LENGTH(N'dbo.site_feedback_batches', N'archived_at') IS NOT NULL
                       AND COL_LENGTH(N'dbo.site_feedback_batches', N'archived_by_user_id') IS NOT NULL
                     THEN 1 ELSE 0 END"
    );
    return (int) $statement->fetchColumn() === 1;
}

function portal_feedback_embed_actions(): array
{
    return ['documents','visual_assets','view','presentation','image_review','admin','admin_document','document_version_compare'];
}

function portal_feedback_embed_request_allowed(array $query,string $method): bool
{
    if(strtoupper($method)!=='GET'||($query['feedback_embed']??null)!=='1'||is_array($query['action']??null))return false;
    $action=(string)($query['action']??'documents');
    return in_array($action,portal_feedback_embed_actions(),true);
}

function portal_feedback_target_actions(array $user): array
{
    $actions = [
        'documents' => '文件庫',
        'visual_assets' => 'AI 圖像／資訊圖表',
        'view' => '文件詳情',
        'presentation' => '線上簡報／文件標註',
        'image_review' => '圖片標註',
    ];
    if (portal_is_admin($user)) {
        $actions['admin'] = '管理後台';
        $actions['admin_document'] = '文件管理詳情';
        $actions['document_version_compare'] = '版本比較';
    }
    return $actions;
}

function portal_feedback_target_action(string $targetUrl, array $user): string
{
    $targetUrl = trim($targetUrl);
    if ($targetUrl === '' || strlen($targetUrl) > 1000 || $targetUrl[0] !== '?' || str_contains($targetUrl, '#')
        || str_contains($targetUrl, "\r") || str_contains($targetUrl, "\n") || str_contains($targetUrl, '\\')) {
        throw new PortalFeedbackHttpException('標註目標必須是站內安全頁面。', 422);
    }
    $parts = parse_url($targetUrl);
    if ($parts === false || array_intersect(array_keys($parts), ['scheme','host','user','pass','port','path','fragment']) !== []) {
        throw new PortalFeedbackHttpException('標註目標必須是站內安全頁面。', 422);
    }
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    if (count($query) > 20 || isset($query['action']) && is_array($query['action'])) {
        throw new PortalFeedbackHttpException('標註目標參數不正確。', 422);
    }
    foreach ($query as $key => $value) {
        if (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) !== 1 || !is_scalar($value)
            || strlen((string) $value) > 300 || str_contains((string) $value, "\0")) {
            throw new PortalFeedbackHttpException('標註目標參數不正確。', 422);
        }
    }
    $action = (string) ($query['action'] ?? 'documents');
    $allSafe = ['documents','visual_assets','view','presentation','image_review','admin','admin_document','document_version_compare'];
    if (!in_array($action, $allSafe, true)) {
        throw new PortalFeedbackHttpException('此站內頁面不允許成為標註目標。', 422);
    }
    if (!array_key_exists($action, portal_feedback_target_actions($user))) {
        throw new PortalFeedbackHttpException('你沒有權限查看此標註目標。', 403);
    }
    return $action;
}

function portal_feedback_bounded_text(mixed $value, int $max, bool $required = false): string
{
    $text = trim((string) $value);
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if (($required && $text === '') || $length > $max || str_contains($text, "\0")) {
        throw new PortalFeedbackHttpException('需求文字長度或內容不正確。', 422);
    }
    return $text;
}

function portal_feedback_float(mixed $value, string $label): float
{
    if (!is_numeric($value)) {
        throw new PortalFeedbackHttpException($label . '不正確。', 422);
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        throw new PortalFeedbackHttpException($label . '不正確。', 422);
    }
    return $number;
}

function portal_feedback_normalize_item(array $payload, array $user): array
{
    $targetUrl = portal_feedback_bounded_text($payload['target_url'] ?? '', 1000, true);
    $targetAction = portal_feedback_target_action($targetUrl, $user);
    $kind = strtolower(portal_feedback_bounded_text($payload['annotation_type'] ?? '', 20, true));
    if (!in_array($kind, ['rectangle','arrow','highlight','text','number'], true)) {
        throw new PortalFeedbackHttpException('標註種類不正確。', 422);
    }
    $viewport = $payload['viewport'] ?? null;
    if (!is_array($viewport)) {
        throw new PortalFeedbackHttpException('畫面尺寸資料不正確。', 422);
    }
    $width = filter_var($viewport['width'] ?? null, FILTER_VALIDATE_INT);
    $height = filter_var($viewport['height'] ?? null, FILTER_VALIDATE_INT);
    $scrollX = filter_var($viewport['scroll_x'] ?? 0, FILTER_VALIDATE_INT);
    $scrollY = filter_var($viewport['scroll_y'] ?? 0, FILTER_VALIDATE_INT);
    $dpr = portal_feedback_float($viewport['dpr'] ?? 1, '畫面像素比例');
    if ($width === false || $height === false || $scrollX === false || $scrollY === false
        || $width < 240 || $width > 8192 || $height < 240 || $height > 8192 || $scrollX < 0 || $scrollY < 0 || $dpr < .25 || $dpr > 8) {
        throw new PortalFeedbackHttpException('畫面尺寸資料不正確。', 422);
    }
    $geometry = $payload['geometry'] ?? null;
    if (!is_array($geometry)) {
        throw new PortalFeedbackHttpException('標註位置資料不正確。', 422);
    }
    if ($kind === 'arrow') {
        $x1 = portal_feedback_float($geometry['x1'] ?? $geometry['x'] ?? null, '箭頭起點');
        $y1 = portal_feedback_float($geometry['y1'] ?? $geometry['y'] ?? null, '箭頭起點');
        $x2 = portal_feedback_float($geometry['x2'] ?? (($geometry['x'] ?? 0) + ($geometry['width'] ?? 0)), '箭頭終點');
        $y2 = portal_feedback_float($geometry['y2'] ?? (($geometry['y'] ?? 0) + ($geometry['height'] ?? 0)), '箭頭終點');
        foreach ([$x1,$y1,$x2,$y2] as $value) {
            if ($value < 0 || $value > 1) {
                throw new PortalFeedbackHttpException('箭頭位置超出畫面範圍。', 422);
            }
        }
        if (hypot($x2 - $x1, $y2 - $y1) < .002) {
            throw new PortalFeedbackHttpException('箭頭長度太短。', 422);
        }
        $normalizedGeometry = ['x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2];
    } else {
        $x = portal_feedback_float($geometry['x'] ?? null, '標註位置');
        $y = portal_feedback_float($geometry['y'] ?? null, '標註位置');
        $boxWidth = portal_feedback_float($geometry['width'] ?? null, '標註寬度');
        $boxHeight = portal_feedback_float($geometry['height'] ?? null, '標註高度');
        if ($boxWidth < 0) { $x += $boxWidth; $boxWidth = abs($boxWidth); }
        if ($boxHeight < 0) { $y += $boxHeight; $boxHeight = abs($boxHeight); }
        if ($x < 0 || $y < 0 || $boxWidth <= 0 || $boxHeight <= 0 || $x + $boxWidth > 1.0000001 || $y + $boxHeight > 1.0000001) {
            throw new PortalFeedbackHttpException('標註位置超出畫面範圍。', 422);
        }
        $normalizedGeometry = ['x'=>$x,'y'=>$y,'width'=>$boxWidth,'height'=>$boxHeight];
    }
    $pageFingerprint = strtolower(portal_feedback_bounded_text($payload['page_fingerprint'] ?? '', 64));
    $elementFingerprint = strtolower(portal_feedback_bounded_text($payload['element_fingerprint'] ?? '', 64));
    foreach ([$pageFingerprint, $elementFingerprint] as $hash) {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new PortalFeedbackHttpException('頁面與元素指紋均為必要欄位。', 422);
        }
    }
    $selector = portal_feedback_bounded_text($payload['selector'] ?? '', 1000);
    if (str_contains($selector, "\r") || str_contains($selector, "\n")) {
        throw new PortalFeedbackHttpException('元素定位資料不正確。', 422);
    }
    $tag = strtolower(portal_feedback_bounded_text($payload['element_tag'] ?? '', 32));
    if ($tag !== '' && preg_match('/\A[a-z][a-z0-9-]{0,31}\z/', $tag) !== 1) {
        throw new PortalFeedbackHttpException('元素種類不正確。', 422);
    }
    return [
        'target_url' => $targetUrl,
        'target_action' => $targetAction,
        'annotation_type' => $kind,
        'geometry' => $normalizedGeometry,
        'viewport' => ['width'=>(int)$width,'height'=>(int)$height,'scroll_x'=>(int)$scrollX,'scroll_y'=>(int)$scrollY,'dpr'=>$dpr],
        'page_fingerprint' => $pageFingerprint,
        'selector' => $selector,
        'element_tag' => $tag,
        'element_text' => portal_feedback_bounded_text($payload['element_text'] ?? '', 1000),
        'element_fingerprint' => $elementFingerprint,
        'instruction' => portal_feedback_bounded_text($payload['instruction'] ?? '', 2000, true),
        'parent_id' => portal_valid_public_id((string) ($payload['parent_id'] ?? '')),
    ];
}

function portal_feedback_transition_allowed(string $from, string $to, bool $isAdmin): bool
{
    $map = [
        'draft' => ['queued_analysis'],
        'queued_analysis' => ['analyzing','analysis_failed'],
        'analyzing' => ['awaiting_approval','analysis_failed'],
        'analysis_failed' => ['queued_analysis'],
        'awaiting_approval' => $isAdmin ? ['completed'] : [],
        'approved' => $isAdmin ? ['completed'] : [],
    ];
    return in_array($to, $map[$from] ?? [], true);
}

function portal_feedback_event(PDO $database, int $batchId, ?int $itemId, string $type, string $outcome, ?int $actorId, string $detail = ''): void
{
    $statement = $database->prepare('INSERT INTO dbo.site_feedback_events (batch_id,item_id,event_type,outcome,actor_user_id,detail) VALUES (?,?,?,?,?,?)');
    $statement->execute([$batchId,$itemId,portal_trim_text($type,64),portal_trim_text($outcome,32),$actorId,$detail === '' ? null : portal_trim_text($detail,1000)]);
}

function portal_feedback_batch(PDO $database, array $user, string $publicId, bool $forUpdate = false): ?array
{
    $id = portal_valid_public_id($publicId);
    if ($id === '') { return null; }
    $lock = $forUpdate ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
    $scope = portal_is_admin($user) ? '' : ' AND b.created_by_user_id = ?';
    $statement = $database->prepare(
        "SELECT b.batch_id,LOWER(CONVERT(varchar(36),b.public_id)) public_id,b.title,b.status_code,b.revision_number,b.analysis_summary,b.execution_summary,
                b.created_by_user_id,b.approved_by_user_id,b.created_at,b.updated_at,b.submitted_at,b.analyzed_at,b.approved_at,b.completed_at,
                b.archived_at,b.archived_by_user_id,
                CONVERT(varchar(18),b.rowver,1) row_version,u.display_name creator_name
         FROM dbo.site_feedback_batches b{$lock} JOIN dbo.auth_users u ON u.user_id=b.created_by_user_id
         WHERE b.public_id=CONVERT(uniqueidentifier,?){$scope}"
    );
    $parameters = [$id];
    if (!portal_is_admin($user)) { $parameters[] = (int) $user['user_id']; }
    $statement->execute($parameters);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function portal_feedback_assert_batch_active(array $batch): void
{
    if (($batch['archived_at'] ?? null) !== null) throw new PortalFeedbackHttpException('此批次已封存；請先到封存資料解除封存。',409);
}

function portal_feedback_items(PDO $database, int $batchId): array
{
    $statement = $database->prepare(
        "SELECT i.item_id,LOWER(CONVERT(varchar(36),i.public_id)) public_id,
                LOWER(CONVERT(varchar(36),p.public_id)) parent_public_id,i.sequence_number,i.target_url,i.target_action,i.page_fingerprint,
                i.viewport_width,i.viewport_height,i.scroll_x,i.scroll_y,i.device_pixel_ratio,i.annotation_type_code,i.geometry_json,
                i.element_selector,i.element_tag,i.element_text,i.element_fingerprint,i.instruction_text,i.structured_title,
                i.structured_requirement,i.acceptance_criteria_json,i.risk_level,CONVERT(varchar(18),i.rowver,1) row_version
         FROM dbo.site_feedback_items i LEFT JOIN dbo.site_feedback_items p ON p.item_id=i.parent_item_id
         WHERE i.batch_id=? AND i.is_deleted=0 ORDER BY i.item_id"
    );
    $statement->execute([$batchId]);
    $items = [];
    foreach ($statement->fetchAll() as $row) {
        try { $row['geometry'] = json_decode((string) $row['geometry_json'], true, 8, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $row['geometry'] = []; }
        try { $row['acceptance_criteria'] = $row['acceptance_criteria_json'] === null ? [] : json_decode((string) $row['acceptance_criteria_json'], true, 8, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $row['acceptance_criteria'] = []; }
        unset($row['geometry_json'],$row['acceptance_criteria_json'],$row['item_id']);
        $items[] = $row;
    }
    return $items;
}

function portal_feedback_page_snapshots(PDO $database,int $batchId): array
{
    if(!portal_feedback_snapshot_schema_available($database))return [];
    $statement=$database->prepare("SELECT LOWER(CONVERT(varchar(36),public_id)) public_id,revision_number,snapshot_kind,target_url,viewport_width,viewport_height,scroll_x,scroll_y,image_width,image_height,image_byte_count,image_sha256,sensitive_content_reviewed,redaction_profile,created_at FROM dbo.site_feedback_snapshots WHERE batch_id=? ORDER BY revision_number DESC,CASE snapshot_kind WHEN 'before' THEN 0 ELSE 1 END,created_at");$statement->execute([$batchId]);$rows=$statement->fetchAll();
    foreach($rows as &$row){$row['image_url']=portal_url('feedback_snapshot_image',['id'=>(string)$row['public_id']]);}unset($row);return $rows;
}

function portal_feedback_snapshot(PDO $database, array $user, string $publicId): array
{
    $batch = portal_feedback_batch($database,$user,$publicId);
    if ($batch === null) { throw new PortalFeedbackHttpException('找不到此修改需求批次。',404); }
    $batch['items'] = portal_feedback_items($database,(int)$batch['batch_id']);
    $batch['snapshots'] = portal_feedback_page_snapshots($database,(int)$batch['batch_id']);
    unset($batch['batch_id']);
    return $batch;
}

function portal_feedback_attach_list_snapshots(PDO $database,array $rows): array
{
    foreach($rows as &$row)$row['snapshots']=[];unset($row);
    if($rows===[]||!portal_feedback_snapshot_schema_available($database)){
        foreach($rows as &$row)unset($row['batch_id']);unset($row);return $rows;
    }
    $batchIds=array_values(array_unique(array_map(static fn(array $row):int=>(int)$row['batch_id'],$rows)));
    $placeholders=implode(',',array_fill(0,count($batchIds),'?'));
    $statement=$database->prepare("SELECT s.batch_id,LOWER(CONVERT(varchar(36),s.public_id)) public_id,s.revision_number,s.snapshot_kind,s.target_url,s.image_width,s.image_height,s.created_at FROM dbo.site_feedback_snapshots s JOIN dbo.site_feedback_batches b ON b.batch_id=s.batch_id AND b.revision_number=s.revision_number WHERE s.batch_id IN ({$placeholders}) ORDER BY s.batch_id,s.snapshot_kind,s.target_url,s.created_at");
    $statement->execute($batchIds);$grouped=[];
    foreach($statement->fetchAll() as $snapshot){$batchId=(int)$snapshot['batch_id'];unset($snapshot['batch_id']);$snapshot['image_url']=portal_url('feedback_snapshot_image',['id'=>(string)$snapshot['public_id']]);$grouped[$batchId][]=$snapshot;}
    foreach($rows as &$row){$batchId=(int)$row['batch_id'];$row['snapshots']=array_values($grouped[$batchId]??[]);unset($row['batch_id']);}unset($row);
    return $rows;
}

function portal_feedback_list(PDO $database, array $user, bool $archived = false): array
{
    $filters = [$archived ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL'];
    $parameters = [];
    if (!portal_is_admin($user)) { $filters[] = 'b.created_by_user_id = ?'; $parameters[] = (int)$user['user_id']; }
    $statement = $database->prepare(
        "SELECT TOP (50) b.batch_id,LOWER(CONVERT(varchar(36),b.public_id)) public_id,b.title,b.status_code,b.revision_number,b.created_at,b.updated_at,b.completed_at,
                b.archived_at,b.archived_by_user_id,u.display_name creator_name,(SELECT COUNT(*) FROM dbo.site_feedback_items i WHERE i.batch_id=b.batch_id AND i.is_deleted=0) item_count
         FROM dbo.site_feedback_batches b JOIN dbo.auth_users u ON u.user_id=b.created_by_user_id
         WHERE ".implode(' AND ',$filters)." ORDER BY b.updated_at DESC,b.batch_id DESC"
    );
    $statement->execute($parameters);
    return portal_feedback_attach_list_snapshots($database,$statement->fetchAll());
}

function portal_feedback_archive_counts(PDO $database, array $user): array
{
    $scope = portal_is_admin($user) ? '' : ' WHERE created_by_user_id = ?';
    $statement=$database->prepare("SELECT SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) active_count,SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) archived_count FROM dbo.site_feedback_batches{$scope}");
    $statement->execute(portal_is_admin($user)?[]:[(int)$user['user_id']]);$row=$statement->fetch()?:[];
    return ['active'=>(int)($row['active_count']??0),'archived'=>(int)($row['archived_count']??0)];
}

function portal_feedback_set_archived(PDO $database,array $user,array $payload,bool $archive): array
{
    $publicId=portal_valid_public_id((string)($payload['batch_id']??''));if($publicId==='')throw new PortalFeedbackHttpException('修改需求批次不正確。',422);
    $ownsTransaction=!$database->inTransaction();if($ownsTransaction)$database->beginTransaction();
    try{
        $batch=portal_feedback_batch($database,$user,$publicId,true);if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);
        $isArchived=$batch['archived_at']!==null;
        if($archive){
            if($isArchived){if($ownsTransaction)$database->commit();return ['ok'=>true,'already_archived'=>true];}
            if(!in_array((string)$batch['status_code'],['draft','awaiting_approval','approved','completed','analysis_failed','rejected'],true))throw new PortalFeedbackHttpException('規格整理中的批次不能封存，請等待整理完成。',409);
            $update=$database->prepare('UPDATE dbo.site_feedback_batches SET archived_at=SYSUTCDATETIME(),archived_by_user_id=?,updated_at=SYSUTCDATETIME() WHERE batch_id=? AND archived_at IS NULL');$update->execute([(int)$user['user_id'],(int)$batch['batch_id']]);
            if($update->rowCount()!==1)throw new PortalFeedbackHttpException('批次封存狀態已變更，請重新整理。',409);
            portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_batch_archived','accepted',(int)$user['user_id'],'soft_archive=1;records_retained=1');
        }else{
            if(!$isArchived){if($ownsTransaction)$database->commit();return ['ok'=>true,'already_active'=>true];}
            $update=$database->prepare('UPDATE dbo.site_feedback_batches SET archived_at=NULL,archived_by_user_id=NULL,updated_at=SYSUTCDATETIME() WHERE batch_id=? AND archived_at IS NOT NULL');$update->execute([(int)$batch['batch_id']]);
            if($update->rowCount()!==1)throw new PortalFeedbackHttpException('批次封存狀態已變更，請重新整理。',409);
            portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_batch_restored','accepted',(int)$user['user_id'],'soft_archive=0;records_retained=1');
        }
        if($ownsTransaction)$database->commit();return ['ok'=>true];
    }catch(Throwable $e){if($ownsTransaction&&$database->inTransaction())$database->rollBack();throw $e;}
}

function portal_feedback_create_batch(PDO $database, array $user, array $payload): array
{
    $title = portal_feedback_bounded_text($payload['title'] ?? '',200,true);
    $publicId = portal_feedback_uuid();
    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) $database->beginTransaction();
    try {
        $insert = $database->prepare("INSERT INTO dbo.site_feedback_batches(public_id,title,status_code,created_by_user_id) OUTPUT INSERTED.batch_id VALUES(CONVERT(uniqueidentifier,?),?,'draft',?)");
        $insert->execute([$publicId,$title,(int)$user['user_id']]);
        $batchId = (int)$insert->fetchColumn();
        portal_feedback_event($database,$batchId,null,'feedback_batch_created','accepted',(int)$user['user_id']);
        if ($ownsTransaction) $database->commit();
    } catch (Throwable $e) { if ($ownsTransaction && $database->inTransaction()) $database->rollBack(); throw $e; }
    return ['ok'=>true,'batch'=>portal_feedback_snapshot($database,$user,$publicId)];
}

function portal_feedback_save_page_snapshot(PDO $database,array $user,array $payload): array
{
    if(!portal_feedback_snapshot_schema_available($database))throw new PortalFeedbackHttpException('歷史截圖資料表尚未啟用。',503);
    $batchPublicId=portal_valid_public_id((string)($payload['batch_id']??''));if($batchPublicId==='')throw new PortalFeedbackHttpException('修改需求批次不正確。',422);$normalized=portal_feedback_validate_snapshot_payload($payload,$user);
    $ownsTransaction=!$database->inTransaction();if($ownsTransaction)$database->beginTransaction();
    try{
        $batch=portal_feedback_batch($database,$user,$batchPublicId,true);if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);portal_feedback_assert_batch_active($batch);
        $isAdmin=portal_is_admin($user);$isOwner=(int)$batch['created_by_user_id']===(int)$user['user_id'];$status=(string)$batch['status_code'];
        if((int)$batch['revision_number']!==$normalized['revision_number'])throw new PortalFeedbackHttpException('批次 Revision 已變更，請重新整理。',409);
        if($normalized['snapshot_kind']==='before'){
            if(!$isOwner&&!$isAdmin)throw new PortalFeedbackHttpException('只有批次建立者或管理員可以保存修改前截圖。',403);
            if(!in_array($status,['draft','analysis_failed','rejected'],true))throw new PortalFeedbackHttpException('修改前截圖只能在送出規格前保存。',409);
        }
        if($normalized['snapshot_kind']==='after'){
            if(!$isAdmin)throw new PortalFeedbackHttpException('只有管理員可以保存修改後截圖。',403);
            if($status!=='completed')throw new PortalFeedbackHttpException('修改後截圖只能在批次完成後保存。',409);
        }
        $target=$database->prepare('SELECT COUNT(*) FROM dbo.site_feedback_items WHERE batch_id=? AND target_url=? AND is_deleted=0');$target->execute([(int)$batch['batch_id'],$normalized['target_url']]);if((int)$target->fetchColumn()<1)throw new PortalFeedbackHttpException('此頁面不屬於目前批次的需求。',422);
        $existing=$database->prepare("SELECT LOWER(CONVERT(varchar(36),public_id)) public_id,target_url,target_url_sha256,viewport_width,viewport_height,scroll_x,scroll_y,mime_type,image_width,image_height,image_byte_count,image_sha256,sensitive_content_reviewed,redaction_profile FROM dbo.site_feedback_snapshots WITH (UPDLOCK,HOLDLOCK) WHERE batch_id=? AND revision_number=? AND snapshot_kind=? AND target_url_sha256=?");$existing->execute([(int)$batch['batch_id'],$normalized['revision_number'],$normalized['snapshot_kind'],$normalized['target_url_sha256']]);$row=$existing->fetch();
        if($row!==false){if(!portal_feedback_snapshot_binding_matches($row,$normalized))throw new PortalFeedbackHttpException('此 Revision 的歷史截圖已存在，但提交的畫面綁定資料不一致。',409);if($ownsTransaction)$database->commit();return ['ok'=>true,'already_exists'=>true,'snapshot_id'=>(string)$row['public_id'],'batch'=>portal_feedback_snapshot($database,$user,$batchPublicId)];}
        $snapshotId=portal_feedback_uuid();$insert=$database->prepare("INSERT INTO dbo.site_feedback_snapshots(public_id,batch_id,revision_number,snapshot_kind,target_url,target_url_sha256,viewport_width,viewport_height,scroll_x,scroll_y,mime_type,image_width,image_height,image_byte_count,image_sha256,sensitive_content_reviewed,redaction_profile,image_bytes,created_by_user_id) VALUES(CONVERT(uniqueidentifier,?),?,?,?,?,?,?,?,?,?,'image/png',?,?,?,?,1,'password-explicit-v1',CONVERT(varbinary(max),?,1),?)");
        $values=[$snapshotId,(int)$batch['batch_id'],$normalized['revision_number'],$normalized['snapshot_kind'],$normalized['target_url'],$normalized['target_url_sha256'],$normalized['viewport_width'],$normalized['viewport_height'],$normalized['scroll_x'],$normalized['scroll_y'],$normalized['image_width'],$normalized['image_height'],$normalized['image_byte_count'],$normalized['image_sha256']];foreach($values as $index=>$value)$insert->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $insert->bindValue(15,'0x'.bin2hex($normalized['image_bytes']),PDO::PARAM_STR);$insert->bindValue(16,(int)$user['user_id'],PDO::PARAM_INT);$insert->execute();
        portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_snapshot_saved','accepted',(int)$user['user_id'],$normalized['snapshot_kind'].' revision '.$normalized['revision_number'].' sha256 '.$normalized['image_sha256'].';sensitive_review=confirmed;redaction=password-explicit-v1');
        if($ownsTransaction)$database->commit();
    }catch(Throwable $e){if($ownsTransaction&&$database->inTransaction())$database->rollBack();throw $e;}
    return ['ok'=>true,'already_exists'=>false,'snapshot_id'=>$snapshotId,'batch'=>portal_feedback_snapshot($database,$user,$batchPublicId)];
}

function portal_handle_feedback_snapshot_image(PDO $database,array $user,string $publicId): never
{
    $publicId=portal_valid_public_id($publicId);if($publicId===''||!portal_feedback_snapshot_schema_available($database))throw new PortalFeedbackHttpException('找不到此歷史截圖。',404);
    $statement=$database->prepare("SELECT s.image_bytes,s.image_byte_count,s.image_sha256,s.image_width,s.image_height,b.created_by_user_id FROM dbo.site_feedback_snapshots s JOIN dbo.site_feedback_batches b ON b.batch_id=s.batch_id WHERE s.public_id=CONVERT(uniqueidentifier,?)");$statement->execute([$publicId]);$row=$statement->fetch();if($row===false)throw new PortalFeedbackHttpException('找不到此歷史截圖。',404);if(!portal_is_admin($user)&&(int)$row['created_by_user_id']!==(int)$user['user_id'])throw new PortalFeedbackHttpException('沒有權限查看此歷史截圖。',403);
    $bytes=is_resource($row['image_bytes'])?stream_get_contents($row['image_bytes']):(string)$row['image_bytes'];if($bytes===false||strlen($bytes)!==(int)$row['image_byte_count']||!hash_equals(strtolower((string)$row['image_sha256']),hash('sha256',$bytes)))throw new PortalFeedbackHttpException('歷史截圖完整性驗證失敗。',500);portal_feedback_validate_png($bytes,(int)$row['image_width'],(int)$row['image_height']);
    $etag='"'.strtolower((string)$row['image_sha256']).'"';if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);header('ETag: '.$etag);exit;}
    header('Content-Type: image/png');header('Content-Length: '.strlen($bytes));header('Content-Disposition: inline; filename="feedback-snapshot-'.$publicId.'.png"');header('Cache-Control: private, max-age=31536000, immutable');header('ETag: '.$etag);header('X-Content-Type-Options: nosniff');echo $bytes;exit;
}

function portal_feedback_save_item(PDO $database, array $user, array $payload): array
{
    $batchPublicId = portal_valid_public_id((string)($payload['batch_id'] ?? ''));
    if ($batchPublicId === '') { throw new PortalFeedbackHttpException('修改需求批次不正確。',422); }
    $normalized = portal_feedback_normalize_item($payload,$user);
    $itemPublicId = portal_valid_public_id((string)($payload['item_id'] ?? ''));
    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) $database->beginTransaction();
    try {
        $batch = portal_feedback_batch($database,$user,$batchPublicId,true);
        if ($batch === null) throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);
        portal_feedback_assert_batch_active($batch);
        if (!in_array((string)$batch['status_code'],['draft','analysis_failed','rejected'],true)) throw new PortalFeedbackHttpException('只有編輯中、規格整理失敗或舊流程退回的批次可以修改。',409);

        $parentId = null;
        if ($normalized['parent_id'] !== '') {
            $parent = $database->prepare('SELECT item_id FROM dbo.site_feedback_items WHERE batch_id=? AND public_id=CONVERT(uniqueidentifier,?) AND is_deleted=0');
            $parent->execute([(int)$batch['batch_id'],$normalized['parent_id']]);
            $parentId = $parent->fetchColumn();
            if ($parentId === false || $normalized['parent_id'] === $itemPublicId) throw new PortalFeedbackHttpException('上層需求不正確。',422);
            $parentId=(int)$parentId;
        }
        $geometryJson=json_encode($normalized['geometry'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($itemPublicId === '') {
            $count=$database->prepare("WITH sequence_candidates(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM sequence_candidates WHERE n<200)
                SELECT MIN(n) FROM sequence_candidates c WHERE NOT EXISTS (SELECT 1 FROM dbo.site_feedback_items i WHERE i.batch_id=? AND i.sequence_number=c.n AND i.is_deleted=0) OPTION (MAXRECURSION 200)");
            $count->execute([(int)$batch['batch_id']]);
            $sequence=(int)$count->fetchColumn();
            if ($sequence<1||$sequence>PORTAL_FEEDBACK_MAX_ITEMS) throw new PortalFeedbackHttpException('單一批次最多可建立 '.PORTAL_FEEDBACK_MAX_ITEMS.' 條需求。',422);
            $itemPublicId=portal_feedback_uuid();
            $insert=$database->prepare("INSERT INTO dbo.site_feedback_items(public_id,batch_id,parent_item_id,sequence_number,target_url,target_action,page_fingerprint,viewport_width,viewport_height,scroll_x,scroll_y,device_pixel_ratio,annotation_type_code,geometry_json,element_selector,element_tag,element_text,element_fingerprint,instruction_text,created_by_user_id) OUTPUT INSERTED.item_id VALUES(CONVERT(uniqueidentifier,?),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $insert->execute([$itemPublicId,(int)$batch['batch_id'],$parentId,$sequence,$normalized['target_url'],$normalized['target_action'],$normalized['page_fingerprint']===''?null:$normalized['page_fingerprint'],$normalized['viewport']['width'],$normalized['viewport']['height'],$normalized['viewport']['scroll_x'],$normalized['viewport']['scroll_y'],$normalized['viewport']['dpr'],$normalized['annotation_type'],$geometryJson,$normalized['selector']===''?null:$normalized['selector'],$normalized['element_tag']===''?null:$normalized['element_tag'],$normalized['element_text']===''?null:$normalized['element_text'],$normalized['element_fingerprint']===''?null:$normalized['element_fingerprint'],$normalized['instruction'],(int)$user['user_id']]);
            $itemId=(int)$insert->fetchColumn();
            $event='feedback_item_created';
        } else {
            $find=$database->prepare('SELECT item_id FROM dbo.site_feedback_items WITH (UPDLOCK,HOLDLOCK) WHERE batch_id=? AND public_id=CONVERT(uniqueidentifier,?) AND is_deleted=0');
            $find->execute([(int)$batch['batch_id'],$itemPublicId]);
            $itemId=$find->fetchColumn();
            if ($itemId===false) throw new PortalFeedbackHttpException('找不到此需求項目。',404);
            if($parentId!==null){
                $cycle=$database->prepare("WITH ancestors(parent_item_id) AS (
                    SELECT parent_item_id FROM dbo.site_feedback_items WHERE batch_id=? AND item_id=? AND is_deleted=0
                    UNION ALL
                    SELECT i.parent_item_id FROM dbo.site_feedback_items i JOIN ancestors a ON i.item_id=a.parent_item_id WHERE i.batch_id=? AND i.is_deleted=0 AND a.parent_item_id IS NOT NULL
                ) SELECT COUNT(*) FROM ancestors WHERE parent_item_id=? OPTION (MAXRECURSION 200)");
                $cycle->execute([(int)$batch['batch_id'],$parentId,(int)$batch['batch_id'],(int)$itemId]);
                if((int)$cycle->fetchColumn()>0)throw new PortalFeedbackHttpException('需求階層不可形成循環。',422);
            }
            $update=$database->prepare('UPDATE dbo.site_feedback_items SET parent_item_id=?,target_url=?,target_action=?,page_fingerprint=?,viewport_width=?,viewport_height=?,scroll_x=?,scroll_y=?,device_pixel_ratio=?,annotation_type_code=?,geometry_json=?,element_selector=?,element_tag=?,element_text=?,element_fingerprint=?,instruction_text=?,structured_title=NULL,structured_requirement=NULL,acceptance_criteria_json=NULL,risk_level=NULL,updated_at=SYSUTCDATETIME() WHERE item_id=? AND is_deleted=0');
            $update->execute([$parentId,$normalized['target_url'],$normalized['target_action'],$normalized['page_fingerprint']===''?null:$normalized['page_fingerprint'],$normalized['viewport']['width'],$normalized['viewport']['height'],$normalized['viewport']['scroll_x'],$normalized['viewport']['scroll_y'],$normalized['viewport']['dpr'],$normalized['annotation_type'],$geometryJson,$normalized['selector']===''?null:$normalized['selector'],$normalized['element_tag']===''?null:$normalized['element_tag'],$normalized['element_text']===''?null:$normalized['element_text'],$normalized['element_fingerprint']===''?null:$normalized['element_fingerprint'],$normalized['instruction'],(int)$itemId]);
            $event='feedback_item_updated';
        }
        $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='draft',revision_number=CASE WHEN status_code IN ('analysis_failed','rejected') THEN revision_number+1 ELSE revision_number END,updated_at=SYSUTCDATETIME() WHERE batch_id=?")->execute([(int)$batch['batch_id']]);
        portal_feedback_event($database,(int)$batch['batch_id'],(int)$itemId,$event,'accepted',(int)$user['user_id']);
        if ($ownsTransaction) $database->commit();
    } catch(Throwable $e){if($ownsTransaction && $database->inTransaction())$database->rollBack();throw $e;}
    return ['ok'=>true,'saved_item_id'=>$itemPublicId,'batch'=>portal_feedback_snapshot($database,$user,$batchPublicId)];
}

function portal_feedback_delete_item(PDO $database, array $user, array $payload): array
{
    $batchId=portal_valid_public_id((string)($payload['batch_id']??''));
    $itemId=portal_valid_public_id((string)($payload['item_id']??''));
    if($batchId===''||$itemId==='')throw new PortalFeedbackHttpException('需求項目不正確。',422);
    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) $database->beginTransaction();
    try{
        $batch=portal_feedback_batch($database,$user,$batchId,true);
        if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);
        portal_feedback_assert_batch_active($batch);
        if(!in_array((string)$batch['status_code'],['draft','analysis_failed','rejected'],true))throw new PortalFeedbackHttpException('只有編輯中、分析失敗或舊流程退回的批次可以刪除需求。',403);
        $find=$database->prepare('SELECT item_id FROM dbo.site_feedback_items WITH (UPDLOCK,HOLDLOCK) WHERE batch_id=? AND public_id=CONVERT(uniqueidentifier,?) AND is_deleted=0');
        $find->execute([(int)$batch['batch_id'],$itemId]);$internal=$find->fetchColumn();
        if($internal===false)throw new PortalFeedbackHttpException('找不到此需求項目。',404);
        $children=$database->prepare('SELECT COUNT(*) FROM dbo.site_feedback_items WHERE batch_id=? AND parent_item_id=? AND is_deleted=0');$children->execute([(int)$batch['batch_id'],(int)$internal]);
        if((int)$children->fetchColumn()>0)throw new PortalFeedbackHttpException('請先移除或改派此需求的子項目。',409);
        $database->prepare('UPDATE dbo.site_feedback_items SET is_deleted=1,deleted_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE item_id=?')->execute([(int)$internal]);
        $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='draft',revision_number=CASE WHEN status_code IN ('analysis_failed','rejected') THEN revision_number+1 ELSE revision_number END,updated_at=SYSUTCDATETIME() WHERE batch_id=?")->execute([(int)$batch['batch_id']]);
        portal_feedback_event($database,(int)$batch['batch_id'],(int)$internal,'feedback_item_deleted','accepted',(int)$user['user_id']);
        if ($ownsTransaction) $database->commit();
    }catch(Throwable $e){if($ownsTransaction && $database->inTransaction())$database->rollBack();throw $e;}
    return ['ok'=>true,'batch'=>portal_feedback_snapshot($database,$user,$batchId)];
}

function portal_feedback_canonical_json(array $payload): string
{
    $sort=function(&$value)use(&$sort):void{if(!is_array($value))return;if(array_is_list($value)){foreach($value as &$item)$sort($item);unset($item);return;}ksort($value,SORT_STRING);foreach($value as &$item)$sort($item);unset($item);};
    $sort($payload);
    return json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function portal_feedback_request_signature(array $request,string $rateKey): string
{
    if(strlen($rateKey)<32)throw new PortalFeedbackHttpException('Hermes 佇列簽章金鑰無效。',503);
    unset($request['queue_hmac']);
    $derived=hash_hmac('sha256','TWWATER-site-feedback-queue-v1',$rateKey,true);
    return hash_hmac('sha256',portal_feedback_canonical_json($request),$derived);
}

function portal_feedback_sign_request(array $request,string $rateKey): array
{
    $request['queue_hmac']=portal_feedback_request_signature($request,$rateKey);
    return $request;
}

function portal_feedback_request_payload(array $batch, array $items, string $kind, string $dispatchId): array
{
    if($kind!=='analysis')throw new PortalFeedbackHttpException('網站只允許產生分析規格，不接受執行要求。',409);
    $safeItems=[];
    foreach($items as $item){
        $base=[
            'item_id'=>(string)$item['public_id'],'parent_item_id'=>$item['parent_public_id']?:null,'sequence'=>(int)$item['sequence_number'],
            'target_url'=>(string)$item['target_url'],'target_action'=>(string)$item['target_action'],'page_fingerprint'=>$item['page_fingerprint']?:null,
            'viewport'=>['width'=>(int)$item['viewport_width'],'height'=>(int)$item['viewport_height'],'scroll_x'=>(int)$item['scroll_x'],'scroll_y'=>(int)$item['scroll_y'],'dpr'=>(float)$item['device_pixel_ratio']],
            'annotation_type'=>(string)$item['annotation_type_code'],'geometry'=>$item['geometry'],
            'structured_title'=>$item['structured_title']?:null,'structured_requirement'=>$item['structured_requirement']?:null,
            'acceptance_criteria'=>$item['acceptance_criteria'],'risk_level'=>$item['risk_level']?:null,
        ];
        if($kind==='analysis'){
            $base['selector']=$item['element_selector']?:null;$base['element_tag']=$item['element_tag']?:null;$base['element_text']=$item['element_text']?:null;
            $base['element_fingerprint']=$item['element_fingerprint']?:null;$base['instruction']=(string)$item['instruction_text'];
        }
        $safeItems[]=$base;
    }
    return ['schema_version'=>1,'dispatch_id'=>$dispatchId,'dispatch_kind'=>$kind,'batch_id'=>(string)$batch['public_id'],'revision'=>(int)$batch['revision_number'],'title'=>(string)$batch['title'],'items'=>$safeItems];
}

function portal_feedback_validate_result(array $result, array $dispatch, array $items): array
{
    $conflict = static fn(string $message): never => throw new PortalFeedbackHttpException($message, 409);
    if ((string)($dispatch['dispatch_kind'] ?? '') !== 'analysis') $conflict('網站不接受執行結果。');
    if ((int)($result['schema_version'] ?? 0) !== 1
        || portal_valid_public_id((string)($result['dispatch_id'] ?? '')) !== portal_valid_public_id((string)($dispatch['dispatch_id'] ?? ''))
        || (string)($result['dispatch_kind'] ?? '') !== (string)($dispatch['dispatch_kind'] ?? '')
        || portal_valid_public_id((string)($result['batch_id'] ?? '')) !== portal_valid_public_id((string)($dispatch['batch_public_id'] ?? ''))
        || (int)($result['revision'] ?? 0) !== (int)($dispatch['revision_number'] ?? 0)
        || !hash_equals(strtolower((string)($dispatch['request_sha256'] ?? '')), strtolower((string)($result['request_sha256'] ?? '')))) {
        $conflict('Hermes 結果與原始需求版本不一致。');
    }
    $summary = portal_feedback_bounded_text($result['summary'] ?? '', 4000, true);
    if (($result['status'] ?? null) === 'failed') {
        $errorCode=portal_feedback_bounded_text($result['error_code'] ?? 'WORKER_FAILED',64,true);
        if(preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/',$errorCode)!==1)$conflict('Hermes 失敗代碼格式不正確。');
        return ['status'=>'failed','summary'=>$summary,'error_code'=>$errorCode];
    }
    $rows = $result['items'] ?? null;
        if (!is_array($rows) || count($rows) !== count($items)) $conflict('Hermes 分析結果的需求項目集合不一致。');
        $expected=[];foreach($items as $item){$id=portal_valid_public_id((string)($item['public_id']??''));if($id==='')$conflict('原始需求項目識別碼不正確。');$expected[$id]=true;}
        $normalized=[];$seen=[];
        foreach($rows as $row){
            if(!is_array($row))$conflict('Hermes 分析結果格式不正確。');
            $id=portal_valid_public_id((string)($row['item_id']??''));if($id===''||!isset($expected[$id])||isset($seen[$id]))$conflict('Hermes 分析結果包含遺失、重複或額外項目。');$seen[$id]=true;
            $criteria=$row['acceptance_criteria']??null;if(!is_array($criteria)||count($criteria)<1||count($criteria)>20)$conflict('Hermes 驗收條件格式不正確。');
            $normalizedCriteria=[];foreach($criteria as $criterion){if(!is_scalar($criterion))$conflict('Hermes 驗收條件格式不正確。');$normalizedCriteria[]=portal_feedback_bounded_text($criterion,500,true);}
            $criteriaJson=json_encode($normalizedCriteria,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(mb_strlen($criteriaJson,'UTF-8')>4000)$conflict('Hermes 驗收條件超過資料庫欄位上限。');
            $risk=strtolower(portal_feedback_bounded_text($row['risk_level']??'',32,true));if(!in_array($risk,['low','medium','high','needs_clarification'],true))$conflict('Hermes 風險分級不正確。');
            $normalized[$id]=['structured_title'=>portal_feedback_bounded_text($row['structured_title']??'',300,true),'structured_requirement'=>portal_feedback_bounded_text($row['structured_requirement']??'',4000,true),'acceptance_criteria'=>$normalizedCriteria,'risk_level'=>$risk];
        }
        if(count($seen)!==count($expected))$conflict('Hermes 分析結果的需求項目集合不一致。');
        return ['summary'=>$summary,'items'=>$normalized];
}

function portal_feedback_pending_dispatches(PDO $database,int $batchId): array
{
    $statement=$database->prepare("SELECT LOWER(CONVERT(varchar(36),d.dispatch_id)) dispatch_id,d.dispatch_kind,d.status_code,d.revision_number,d.request_sha256,
        LOWER(CONVERT(varchar(36),b.public_id)) batch_public_id FROM dbo.site_feedback_dispatches d JOIN dbo.site_feedback_batches b ON b.batch_id=d.batch_id
        WHERE d.batch_id=? AND d.dispatch_kind='analysis' AND d.status_code IN ('queued','processing') ORDER BY d.created_at,d.dispatch_kind");
    $statement->execute([$batchId]);return $statement->fetchAll();
}

function portal_feedback_reconcile_analysis_request(PDO $database,array $config,array $user,array $batch,array $dispatch): void
{
    if((string)$dispatch['dispatch_kind']!=='analysis'||(string)$dispatch['status_code']!=='queued'||(string)$batch['status_code']!=='queued_analysis')return;
    try{$directory=portal_feedback_queue_directory($config,'analysis','requests');}catch(Throwable){return;}
    $final=$directory.DIRECTORY_SEPARATOR.(string)$dispatch['dispatch_id'].'.json';
    $processing=$directory.DIRECTORY_SEPARATOR.(string)$dispatch['dispatch_id'].'.processing';
    if((is_file($final)&&!is_link($final))||(is_file($processing)&&!is_link($processing)))return;
    $items=portal_feedback_items($database,(int)$batch['batch_id']);
    $request=portal_feedback_sign_request(portal_feedback_request_payload($batch,$items,'analysis',(string)$dispatch['dispatch_id']),(string)$config['rate_key']);
    $json=portal_feedback_canonical_json($request);$hash=hash('sha256',$json);
    if(!hash_equals((string)$dispatch['request_sha256'],$hash)){
        $database->beginTransaction();
        try{$database->prepare("UPDATE dbo.site_feedback_dispatches SET status_code='failed',error_code='RECONCILE_HASH_MISMATCH',completed_at=SYSUTCDATETIME() WHERE dispatch_id=CONVERT(uniqueidentifier,?) AND status_code='queued'")->execute([(string)$dispatch['dispatch_id']]);$database->prepare("UPDATE dbo.site_feedback_batches SET status_code='analysis_failed',updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code='queued_analysis'")->execute([(int)$batch['batch_id']]);portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_analysis_reconcile_hash_failed','failed',null,'dispatch='.(string)$dispatch['dispatch_id']);$database->commit();}catch(Throwable $e){if($database->inTransaction())$database->rollBack();throw $e;}
        return;
    }
    try{portal_feedback_publish_request($config,'analysis',(string)$dispatch['dispatch_id'],$json);}catch(Throwable){/* Polling retries transient queue races/failures. */}
}

function portal_feedback_sync_results(PDO $database,array $config,array $user,string $batchPublicId): void
{
    $batch=portal_feedback_batch($database,$user,$batchPublicId);if($batch===null)return;
    foreach(portal_feedback_pending_dispatches($database,(int)$batch['batch_id']) as $dispatch){
        portal_feedback_reconcile_analysis_request($database,$config,$user,$batch,$dispatch);
        try{$directory=portal_feedback_queue_directory($config,(string)$dispatch['dispatch_kind'],'results');}catch(Throwable){continue;}
        $path=$directory.DIRECTORY_SEPARATOR.(string)$dispatch['dispatch_id'].'.json';if(!is_file($path)||is_link($path))continue;
        $size=filesize($path);if($size===false||$size<20||$size>PORTAL_FEEDBACK_JSON_MAX_BYTES)continue;
        $raw=(string)file_get_contents($path);try{$decoded=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(Throwable){continue;}if(!is_array($decoded))continue;
        $items=portal_feedback_items($database,(int)$batch['batch_id']);
        try{$validated=portal_feedback_validate_result($decoded,$dispatch,$items);}catch(PortalFeedbackHttpException $e){
            $database->beginTransaction();
            try{
                $database->prepare("UPDATE dbo.site_feedback_dispatches SET status_code='failed',error_code='RESULT_BINDING_MISMATCH',completed_at=SYSUTCDATETIME() WHERE dispatch_id=CONVERT(uniqueidentifier,?) AND status_code IN ('queued','processing')")->execute([(string)$dispatch['dispatch_id']]);
                $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='analysis_failed',updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code IN ('queued_analysis','analyzing')")->execute([(int)$batch['batch_id']]);
                portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_result_binding_failed','failed',null,'dispatch='.(string)$dispatch['dispatch_id']);
                $database->commit();
            }catch(Throwable $failure){if($database->inTransaction())$database->rollBack();throw $failure;}
            continue;
        }
        $resultHash=hash('sha256',$raw);$database->beginTransaction();
        try{
            $locked=portal_feedback_batch($database,$user,$batchPublicId,true);if($locked===null)throw new RuntimeException('Feedback batch disappeared.');
            if(($validated['status']??null)==='failed'){
                if(!in_array((string)$locked['status_code'],['queued_analysis','analyzing'],true)){$database->rollBack();continue;}
                $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='analysis_failed',analysis_summary=?,updated_at=SYSUTCDATETIME() WHERE batch_id=?")->execute([$validated['summary'],(int)$locked['batch_id']]);
                portal_feedback_event($database,(int)$locked['batch_id'],null,'feedback_analysis_failed','failed',null,'dispatch='.(string)$dispatch['dispatch_id'].';error='.$validated['error_code']);
                $database->prepare("UPDATE dbo.site_feedback_dispatches SET status_code='failed',result_sha256=?,error_code=?,completed_at=SYSUTCDATETIME() WHERE dispatch_id=CONVERT(uniqueidentifier,?) AND status_code IN ('queued','processing')")->execute([$resultHash,$validated['error_code'],(string)$dispatch['dispatch_id']]);
                $database->commit();continue;
            }
            if(!in_array((string)$locked['status_code'],['queued_analysis','analyzing'],true)){$database->rollBack();continue;}
            $update=$database->prepare('UPDATE dbo.site_feedback_items SET structured_title=?,structured_requirement=?,acceptance_criteria_json=?,risk_level=?,updated_at=SYSUTCDATETIME() WHERE batch_id=? AND public_id=CONVERT(uniqueidentifier,?) AND is_deleted=0');
            foreach($validated['items'] as $id=>$analysis){$update->execute([$analysis['structured_title'],$analysis['structured_requirement'],json_encode($analysis['acceptance_criteria'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$analysis['risk_level'],(int)$locked['batch_id'],$id]);if($update->rowCount()!==1)throw new RuntimeException('Feedback item changed during result import.');}
            $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='awaiting_approval',analysis_summary=?,analyzed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code IN ('queued_analysis','analyzing')")->execute([$validated['summary'],(int)$locked['batch_id']]);
            portal_feedback_event($database,(int)$locked['batch_id'],null,'feedback_analysis_completed','accepted',null,'dispatch='.(string)$dispatch['dispatch_id']);
            $database->prepare("UPDATE dbo.site_feedback_dispatches SET status_code='completed',result_sha256=?,completed_at=SYSUTCDATETIME() WHERE dispatch_id=CONVERT(uniqueidentifier,?) AND status_code IN ('queued','processing')")->execute([$resultHash,(string)$dispatch['dispatch_id']]);
            $database->commit();
        }catch(Throwable $e){if($database->inTransaction())$database->rollBack();throw $e;}
    }
}

function portal_feedback_queue_directory(array $config,string $kind,string $direction): string
{
    if($kind!=='analysis'||!in_array($direction,['requests','results'],true))throw new RuntimeException('Invalid feedback queue direction.');
    $root=realpath((string)($config['staged_commit_root']??''));
    if($root===false||!is_dir($root)||is_link($root)||!portal_path_is_outside_application_root($root))throw new PortalFeedbackHttpException('Hermes 分析通道尚未啟用。',503);
    $path=$root.DIRECTORY_SEPARATOR.'site-feedback'.DIRECTORY_SEPARATOR.$kind.'-'.$direction;
    $real=realpath($path);
    if($real===false||!is_dir($real)||is_link($real)||!str_starts_with(strtolower($real.DIRECTORY_SEPARATOR),strtolower($root.DIRECTORY_SEPARATOR)))throw new PortalFeedbackHttpException('Hermes 分析通道尚未啟用。',503);
    return $real;
}

function portal_feedback_publish_request(array $config,string $kind,string $dispatchId,string $json): void
{
    if(strlen($json)>PORTAL_FEEDBACK_JSON_MAX_BYTES)throw new PortalFeedbackHttpException('整批需求超過自動分析上限。',413);
    $directory=portal_feedback_queue_directory($config,$kind,'requests');
    $final=$directory.DIRECTORY_SEPARATOR.$dispatchId.'.json';
    if(is_file($final)||is_link($final))throw new PortalFeedbackHttpException('此分析要求已存在。',409);
    $pending=$directory.DIRECTORY_SEPARATOR.'.'.$dispatchId.'.'.bin2hex(random_bytes(6)).'.pending';
    if(file_put_contents($pending,$json,LOCK_EX)!==strlen($json)||!rename($pending,$final)){@unlink($pending);throw new PortalFeedbackHttpException('無法送出 Hermes 分析要求。',503);}
}

function portal_feedback_missing_before_targets(PDO $database,int $batchId,int $revision): array
{
    if(!portal_feedback_snapshot_schema_available($database))throw new PortalFeedbackHttpException('歷史截圖資料表尚未啟用。',503);
    $statement=$database->prepare("SELECT i.target_url FROM dbo.site_feedback_items i LEFT JOIN dbo.site_feedback_snapshots s ON s.batch_id=i.batch_id AND s.revision_number=? AND s.snapshot_kind='before' AND s.target_url=i.target_url WHERE i.batch_id=? AND i.is_deleted=0 GROUP BY i.target_url HAVING COUNT(s.snapshot_id)=0 ORDER BY i.target_url");$statement->execute([$revision,$batchId]);return array_map(static fn(array $row):string=>(string)$row['target_url'],$statement->fetchAll());
}

function portal_feedback_submit(PDO $database,array $config,array $user,array $payload): array
{
    $publicId=portal_valid_public_id((string)($payload['batch_id']??''));if($publicId==='')throw new PortalFeedbackHttpException('修改需求批次不正確。',422);
    $dispatchId=portal_feedback_uuid();$json='';$hash='';
    $database->beginTransaction();
    try{
        $batch=portal_feedback_batch($database,$user,$publicId,true);if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);portal_feedback_assert_batch_active($batch);
        if((string)$batch['status_code']==='analysis_failed'){
            $revision=(int)$batch['revision_number'];
            $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='draft',updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code='analysis_failed' AND revision_number=?")->execute([(int)$batch['batch_id'],$revision]);
            $batch['status_code']='draft';
            portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_analysis_retry','accepted',(int)$user['user_id'],'same_revision='.(int)$batch['revision_number'].';snapshots_optional');
        }
        if(!portal_feedback_transition_allowed((string)$batch['status_code'],'queued_analysis',false))throw new PortalFeedbackHttpException('此批次目前不能送出分析。',409);
        $items=portal_feedback_items($database,(int)$batch['batch_id']);if($items===[])throw new PortalFeedbackHttpException('請至少建立一條修改需求。',422);
        $request=portal_feedback_sign_request(portal_feedback_request_payload($batch,$items,'analysis',$dispatchId),(string)$config['rate_key']);$json=portal_feedback_canonical_json($request);$hash=hash('sha256',$json);
        $insert=$database->prepare("INSERT INTO dbo.site_feedback_dispatches(dispatch_id,batch_id,dispatch_kind,status_code,revision_number,request_sha256) VALUES(CONVERT(uniqueidentifier,?),?,'analysis','queued',?,?)");$insert->execute([$dispatchId,(int)$batch['batch_id'],(int)$batch['revision_number'],$hash]);
        $database->prepare("UPDATE dbo.site_feedback_batches SET status_code='queued_analysis',submitted_at=COALESCE(submitted_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code IN ('draft','analysis_failed')")->execute([(int)$batch['batch_id']]);
        portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_analysis_queued','accepted',(int)$user['user_id'],'dispatch='.$dispatchId.';sha256='.$hash);
        $database->commit();
    }catch(Throwable $e){if($database->inTransaction())$database->rollBack();throw $e;}
    try{portal_feedback_publish_request($config,'analysis',$dispatchId,$json);}
    catch(Throwable $e){$database->beginTransaction();try{$database->prepare("UPDATE dbo.site_feedback_dispatches SET status_code='failed',error_code='QUEUE_WRITE_FAILED',completed_at=SYSUTCDATETIME() WHERE dispatch_id=CONVERT(uniqueidentifier,?) AND status_code='queued'")->execute([$dispatchId]);$database->prepare("UPDATE dbo.site_feedback_batches SET status_code='analysis_failed',updated_at=SYSUTCDATETIME() WHERE public_id=CONVERT(uniqueidentifier,?) AND status_code='queued_analysis'")->execute([$publicId]);portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_analysis_queue_failed','failed',(int)$user['user_id'],'dispatch='.$dispatchId);$database->commit();}catch(Throwable $ignored){if($database->inTransaction())$database->rollBack();}throw $e;}
    return ['ok'=>true,'batch'=>portal_feedback_snapshot($database,$user,$publicId)];
}

function portal_feedback_complete(PDO $database,array $user,array $payload): array
{
    if(!portal_is_admin($user))throw new PortalFeedbackHttpException('只有 Gary／管理員可以確認 Discord 修改已完成。',403);
    $publicId=portal_valid_public_id((string)($payload['batch_id']??''));
    if($publicId==='')throw new PortalFeedbackHttpException('修改需求批次不正確。',422);
    $summary=portal_feedback_bounded_text($payload['summary']??'',4000,true);
    $ownsTransaction=!$database->inTransaction();
    if($ownsTransaction)$database->beginTransaction();
    try{
        $batch=portal_feedback_batch($database,$user,$publicId,true);
        if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);
        portal_feedback_assert_batch_active($batch);
        if(!portal_feedback_transition_allowed((string)$batch['status_code'],'completed',true))throw new PortalFeedbackHttpException('請先整理精確規格，並確認外部修改確實完成。',409);
        $update=$database->prepare("UPDATE dbo.site_feedback_batches SET status_code='completed',execution_summary=?,completed_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE batch_id=? AND status_code IN ('awaiting_approval','approved')");
        $update->execute([$summary,(int)$batch['batch_id']]);
        if($update->rowCount()!==1)throw new PortalFeedbackHttpException('批次狀態已變更，請重新整理。',409);
        portal_feedback_event($database,(int)$batch['batch_id'],null,'feedback_batch_completed_external','accepted',(int)$user['user_id'],'External Discord work acknowledged by administrator; website did not execute code.');
        if($ownsTransaction)$database->commit();
    }catch(Throwable $e){if($ownsTransaction&&$database->inTransaction())$database->rollBack();throw $e;}
    return ['ok'=>true,'batch'=>portal_feedback_snapshot($database,$user,$publicId)];
}

function portal_render_site_feedback(PDO $database,array $config,array $user,string $selected=''): void
{
    if(!portal_feedback_schema_available($database)){portal_render_page('畫面修改需求','<section class="panel narrow"><p class="eyebrow">VISUAL FEEDBACK</p><h1>畫面修改需求尚未啟用</h1><p>資料庫結構尚待管理員部署。</p></section>',$user,false,'wide-readable-page site-feedback-page',true);return;}
    $archiveView=((string)($_GET['history']??''))==='archived';$batch=null;$selectedId=portal_valid_public_id($selected);
    if($selectedId!==''){$batch=portal_feedback_snapshot($database,$user,$selectedId);$archiveView=$batch['archived_at']!==null;}
    $counts=portal_feedback_archive_counts($database,$user);$batches=portal_feedback_list($database,$user,$archiveView);
    $targets=[];foreach(portal_feedback_target_actions($user) as $action=>$label){if(in_array($action,['view','presentation','image_review','admin_document','document_version_compare'],true))continue;$targets[]=['action'=>$action,'label'=>$label,'url'=>portal_url($action)];}
    $state=['batches'=>$batches,'selected_batch'=>$batch,'history_mode'=>$archiveView?'archived':'active','archive_counts'=>$counts,'targets'=>$targets,'embed_actions'=>portal_feedback_embed_actions(),'permissions'=>['admin'=>portal_is_admin($user)],'csrf_token'=>portal_csrf_token(),'urls'=>['create'=>portal_url('feedback_batch_create'),'save_item'=>portal_url('feedback_item_save'),'delete_item'=>portal_url('feedback_item_delete'),'snapshot_save'=>portal_url('feedback_snapshot_save'),'submit'=>portal_url('feedback_submit'),'status'=>portal_url('feedback_status'),'complete'=>portal_url('feedback_complete'),'archive'=>portal_url('feedback_batch_archive'),'restore'=>portal_url('feedback_batch_restore')]];
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    $content='<section class="site-feedback-intro"><div><p class="eyebrow">VISUAL REQUIREMENTS</p><h1>畫面修改需求</h1><p>沿用線上簡報標註方式，在實際網站畫面上框選、畫箭頭、螢光、文字或編號，再逐條整理成問題樹。</p></div><div class="site-feedback-intro-actions"><button type="button" class="secondary-button" data-feedback-batches>修改歷程</button><button type="button" class="primary-button" data-feedback-create>新增需求批次</button></div></section>'
        .'<div id="site-feedback-app" data-site-feedback-app><noscript><section class="panel"><p>此功能需要 JavaScript。</p></section></noscript></div>'
        .'<script type="application/json" id="site-feedback-state">'.$json.'</script>';
    portal_render_page('畫面修改需求',$content,$user,false,'wide-readable-page site-feedback-page',true);
}

function portal_handle_feedback_batch_create(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_create_batch($database,$user,portal_feedback_read_json_body()));}
function portal_handle_feedback_item_save(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_save_item($database,$user,portal_feedback_read_json_body()));}
function portal_handle_feedback_item_delete(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_delete_item($database,$user,portal_feedback_read_json_body()));}
function portal_handle_feedback_snapshot_save(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_save_page_snapshot($database,$user,portal_feedback_read_snapshot_json_body()));}
function portal_handle_feedback_submit(PDO $database,array $config,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_submit($database,$config,$user,portal_feedback_read_json_body()));}
function portal_handle_feedback_status(PDO $database,array $config,array $user,string $ignored=''): never{portal_feedback_json_action(function()use($database,$config,$user):array{$payload=portal_feedback_read_json_body();$id=portal_valid_public_id((string)($payload['batch_id']??''));if($id==='')throw new PortalFeedbackHttpException('修改需求批次不正確。',422);$batch=portal_feedback_batch($database,$user,$id);if($batch===null)throw new PortalFeedbackHttpException('找不到此修改需求批次。',404);portal_feedback_assert_batch_active($batch);portal_feedback_sync_results($database,$config,$user,$id);return ['ok'=>true,'batch'=>portal_feedback_snapshot($database,$user,$id)];});}

function portal_handle_feedback_complete(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_complete($database,$user,portal_feedback_read_json_body()));}
function portal_handle_feedback_batch_archive(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_set_archived($database,$user,portal_feedback_read_json_body(),true));}
function portal_handle_feedback_batch_restore(PDO $database,array $user): never{portal_feedback_json_action(fn()=>portal_feedback_set_archived($database,$user,portal_feedback_read_json_body(),false));}
