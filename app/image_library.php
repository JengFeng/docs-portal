<?php
declare(strict_types=1);

const PORTAL_IMAGE_EXTENSIONS = ['png','jpg','jpeg','webp','gif'];
const PORTAL_IMAGE_EXPORT_MAX_ITEMS = 40;
const PORTAL_IMAGE_ANNOTATION_MAX_ITEMS = 100;
const PORTAL_IMAGE_EXPORT_MAX_SOURCE_BYTES = 629145600;
const PORTAL_IMAGE_EXPORT_MAX_OUTPUT_BYTES = 268435456;
const PORTAL_IMAGE_EXPORT_MAX_ANNOTATIONS = 1000;

function portal_is_image_extension(string $extension): bool
{
    return in_array(strtolower($extension), PORTAL_IMAGE_EXTENSIONS, true);
}

function portal_image_schema_available(PDO $database): bool
{
    return (int) $database->query("SELECT CASE WHEN OBJECT_ID(N'dbo.image_annotations', N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
}

function portal_image_sort(array $source): string
{
    if(is_array($source['sort']??null)) return 'modified_desc';
    $sort=(string)($source['sort']??'modified_desc');
    return in_array($sort,['created_desc','created_asc','modified_desc','modified_asc','name_asc','name_desc'],true)?$sort:'modified_desc';
}

function portal_image_sort_labels(): array
{
    return [
        'created_desc'=>'建立時間：新到舊','created_asc'=>'建立時間：舊到新',
        'modified_desc'=>'修改時間：新到舊','modified_asc'=>'修改時間：舊到新',
        'name_asc'=>'名稱：昇冪','name_desc'=>'名稱：降冪',
    ];
}

function portal_image_order_by(string $sort): string
{
    $orders=[
        'created_desc'=>'d.created_at DESC, d.document_id DESC','created_asc'=>'d.created_at ASC, d.document_id ASC',
        'modified_desc'=>'d.source_modified_at DESC, d.document_id DESC','modified_asc'=>'d.source_modified_at ASC, d.document_id ASC',
        'name_asc'=>'d.title ASC, d.file_name ASC, d.document_id ASC','name_desc'=>'d.title DESC, d.file_name DESC, d.document_id DESC',
    ];
    return $orders[$sort]??$orders['modified_desc'];
}

function portal_render_image_sort_menu(string $sort): string
{
    $options='';
    foreach(portal_image_sort_labels() as $value=>$label) $options.='<option value="'.portal_e($value).'"'.($sort===$value?' selected':'').'>'.portal_e($label).'</option>';
    $current=portal_image_sort_labels()[$sort]??portal_image_sort_labels()['modified_desc'];
    $icon='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h10M4 12h7M4 18h4M17 9v9m0 0-3-3m3 3 3-3"/></svg>';
    return '<form method="get" class="library-sort-menu" data-library-sort-menu><input type="hidden" name="action" value="visual_assets">'
        .'<details><summary aria-label="圖片排序" title="圖片排序：'.portal_e($current).'">'.$icon.'<span class="library-sort-current">排序</span></summary>'
        .'<div class="library-sort-menu-panel"><label>排序方式<select name="sort">'.$options.'</select></label><small>選取後立即重新排列圖片。</small><noscript><button class="secondary-button" type="submit">套用排序</button></noscript></div></details></form>';
}

function portal_image_clean_style(mixed $value): array
{
    $style=[];
    if(!is_array($value)) return $style;
    foreach(['stroke','fill','textColor'] as $key) {
        $candidate=strtolower((string)($value[$key]??''));
        if(preg_match('/^#[0-9a-f]{6}$/D',$candidate)===1) $style[$key]=$candidate;
    }
    if(isset($value['opacity'])&&is_numeric((string)$value['opacity'])) $style['opacity']=max(.05,min(1.0,round((float)$value['opacity'],2)));
    if(isset($value['strokeWidth'])&&is_numeric((string)$value['strokeWidth'])) $style['strokeWidth']=max(1,min(12,(int)$value['strokeWidth']));
    if(isset($value['fontSize'])&&is_numeric((string)$value['fontSize'])) $style['fontSize']=max(10,min(72,(int)$value['fontSize']));
    return $style;
}

function portal_image_clean_geometry(string $type,mixed $value,float $x,float $y,float $width,float $height): array
{
    $geometry=['x'=>$x,'y'=>$y,'width'=>$width,'height'=>$height];
    if(!is_array($value)) $value=[];
    if($type==='arrow') {
        foreach(['x1','y1','x2','y2'] as $key) {
            $number=$value[$key]??null;
            if(!is_numeric((string)$number)||!is_finite((float)$number)||(float)$number<0||(float)$number>1) throw new RuntimeException('INVALID_ANNOTATION');
            $geometry[$key]=round((float)$number,8);
        }
    }
    if($type==='text') {
        $text=portal_trim_text((string)($value['text']??''),500);
        if($text==='') throw new RuntimeException('INVALID_ANNOTATION');
        $geometry['text']=$text;
    }
    if($type==='number') {
        $number=filter_var($value['number']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>9999]]);
        if($number===false) throw new RuntimeException('INVALID_ANNOTATION');
        $geometry['number']=(int)$number;
    }
    return $geometry;
}

function portal_list_image_documents(PDO $database, array $config, array $user, string $sort='modified_desc'): array
{
    $sql = "SELECT TOP (100) d.document_id, CONVERT(varchar(36), d.public_id) AS public_id,
                   d.relative_path, d.file_name, d.title, d.extension, d.file_size_bytes, d.source_modified_at,
                   d.status_code, d.summary, d.content_hash, d.created_at, d.current_version_id,
                   d.version_capture_status, d.version_capture_error_code, LOWER(cv.source_content_hash) current_version_hash
            FROM dbo.documents d
            LEFT JOIN dbo.document_versions cv ON cv.version_id=d.current_version_id AND cv.document_id=d.document_id
            WHERE d.extension IN ('png','jpg','jpeg','webp','gif')";
    if (!portal_can_edit_documents($user)) {
        $sql .= " AND d.status_code = 'published'";
    }
    $sql.=' ORDER BY '.portal_image_order_by($sort);
    $documents = [];
    foreach ($database->query($sql)->fetchAll() as $document) {
        try {
            portal_document_file_path($config, $document);
        } catch (RuntimeException $exception) {
            error_log('TWWATER visual asset omitted unavailable catalog row: ' . (string) ($document['public_id'] ?? 'unknown'));
            continue;
        }
        $documents[] = $document;
    }
    return $documents;
}

function portal_render_image_library_panel(PDO $database, array $config, array $user, string $sort='modified_desc'): string
{
    if (!portal_image_schema_available($database)) {
        return '<section class="panel image-library-panel"><h2>AI 圖像／資訊圖表</h2><p class="muted">圖片管理資料表尚未完成部署。</p></section>';
    }
    $documents = portal_list_image_documents($database, $config, $user, $sort);
    $cards = '';
    foreach ($documents as $document) {
        $publicId = (string) $document['public_id'];
        $cards .= '<article class="image-asset-card">'
            . '<a class="image-card-preview" href="' . portal_url('image_review', ['id' => $publicId]) . '">'
            . '<img loading="lazy" src="' . portal_url('image_asset', ['id' => $publicId, 'expected_hash' => (string) $document['content_hash']]) . '" alt="' . portal_e((string) $document['title']) . '"></a>'
            . '<div class="image-card-body"><strong>' . portal_e((string) $document['title']) . '</strong>'
            . '<span>' . portal_e(strtoupper((string) $document['extension'])) . ' · ' . portal_e(portal_format_file_size((int) $document['file_size_bytes'])) . '</span>'
            . '<div class="image-order-controls"><button type="button" class="link-button" data-image-move-up aria-label="將此圖片往前移">↑ 前移</button><button type="button" class="link-button" data-image-move-down aria-label="將此圖片往後移">↓ 後移</button></div>'
            . '<div class="image-card-actions"><label class="image-select"><input type="checkbox" name="document_ids[]" value="' . portal_e($publicId) . '" data-image-export-item> 選取此圖</label>'
            . '<a class="text-link" href="' . portal_url('image_review', ['id' => $publicId]) . '">預覽與框選標註</a></div></div></article>';
    }
    if ($cards === '') {
        $cards = '<div class="empty-state">目前沒有圖片資產。Phase 1由管理者於伺服器正式文件庫放置 PNG、JPG、WEBP 或 GIF 並重新索引；網站不提供上傳。</div>';
    }
    return '<section class="panel image-library-panel" data-image-library><div class="section-heading"><div><p class="eyebrow">VISUAL ASSET LIBRARY</p><h2>AI 圖像／資訊圖表</h2><p>資料由受保護正式文件庫讀取；網站不提供上傳。此處提供預覽、框選修改註記及多張圖片合併匯出。</p></div><div class="library-result-actions"><span class="count-badge">' . count($documents) . ' 張</span>'.portal_render_image_sort_menu($sort).'</div></div>'
        . '<form method="post" action="' . portal_url('image_export') . '" data-image-export-form>' . portal_csrf_field()
        . '<div class="image-export-toolbar"><div class="image-export-summary"><label><input type="checkbox" data-select-all-images> 全選目前圖片</label><span data-image-selected-count>已選 0 張</span></div>'
        . '<div class="image-export-options panel"><label><span class="image-export-field-name">輸出格式</span><select name="format"><option value="gif">動態 GIF</option><option value="pdf">PDF</option><option value="pptx">PPTX</option></select></label>'
        . '<label><span class="image-export-field-name">GIF 每張秒數</span><input type="number" name="frame_duration_ms" min="200" max="10000" step="100" value="1500"></label>'
        . '<label><span class="image-export-field-name">合併標註</span><span class="image-export-checkbox-control"><input type="checkbox" name="include_annotations" value="1" checked> 包含目前標註</span></label>'
        . '<button class="primary-button" type="submit">合併下載</button></div></div>'
        . '<div class="image-asset-grid">' . $cards . '</div></form></section>';
}

function portal_render_image_library_page(PDO $database, array $config, array $user): void
{
    $sort=portal_image_sort($_GET);
    portal_render_page('AI 圖像／資訊圖表', portal_render_image_library_panel($database, $config, $user, $sort), $user, false, 'wide-readable-page visual-assets-page');
}

function portal_image_annotations(PDO $database, int $documentId, string $hash): array
{
    if (!portal_image_schema_available($database)) return [];
    $statement = $database->prepare(
        "SELECT CONVERT(varchar(36), a.public_id) AS public_id, a.x_norm, a.y_norm, a.width_norm, a.height_norm,
                a.annotation_type_code, a.geometry_json, a.style_json,
                a.note_text, a.status_code, a.created_by_user_id, u.display_name, a.created_at, a.updated_at
         FROM dbo.image_annotations a INNER JOIN dbo.auth_users u ON u.user_id = a.created_by_user_id
         WHERE a.document_id = ? AND a.source_content_hash = ?
         ORDER BY a.created_at, a.annotation_id"
    );
    $statement->execute([$documentId, $hash]);
    $rows=$statement->fetchAll();
    foreach($rows as &$row) {
        $row['geometry']=json_decode((string)$row['geometry_json'],true,32,JSON_THROW_ON_ERROR);
        $row['style']=json_decode((string)$row['style_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['geometry_json'],$row['style_json']);
    }
    unset($row);
    return $rows;
}

function portal_image_requirement_build(array $document, string $sourceHash, array $annotations, array $binding = []): array
{
    $labels=['rectangle'=>'框選','arrow'=>'箭頭','highlight'=>'螢光','text'=>'文字','number'=>'編號'];
    $items=[];
    foreach($annotations as $index=>$annotation){
        $type=(string)$annotation['annotation_type_code'];
        $items[]=[
            'order'=>$index+1,'annotationId'=>(string)$annotation['public_id'],'type'=>$type,'typeLabel'=>$labels[$type]??'框選',
            'instruction'=>(string)$annotation['note_text'],
            'position'=>['x'=>(float)$annotation['x_norm'],'y'=>(float)$annotation['y_norm'],'width'=>(float)$annotation['width_norm'],'height'=>(float)$annotation['height_norm']],
            'geometry'=>(array)$annotation['geometry'],'style'=>(array)$annotation['style'],
        ];
    }
    $payload=[
        'schemaVersion'=>'twwater-image-change-request-v2','taskKind'=>'image-modification',
        'document'=>['publicId'=>(string)$document['public_id'],'title'=>(string)$document['title'],'relativePath'=>(string)$document['relative_path'],'sourceHash'=>$sourceHash],
        'binding'=>$binding,
        'annotations'=>$items,
        'safety'=>['archiveBeforeAutomaticReplace'=>true,'restoreArchivesCurrentFirst'=>true,'stopOnLocationConflict'=>true,'noExternalImageApiWithoutApproval'=>true],
    ];
    $lines=['請依下列已儲存的圖片標註修改指定圖片。','文件 ID：'.$payload['document']['publicId'],'圖片：'.$payload['document']['title'],'相對路徑：'.$payload['document']['relativePath'],'來源 SHA-256：'.$sourceHash,''];
    foreach($items as $item){$lines[]=$item['order'].'. '.$item['typeLabel'].'；annotation_id='.$item['annotationId'].'；type='.$item['type']."\n   修改：".$item['instruction']."\n   position=".json_encode($item['position'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n   geometry=".json_encode($item['geometry'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n   style=".json_encode($item['style'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    $lines[]='';$lines[]='安全界線：AI修改結果通過來源SHA-256、格式、尺寸與ROI驗證後，必須先封存目前正式原圖，再原子替換同一路徑；還原前也必須先封存當下版本。不得自行呼叫外部圖片 API；定位資料衝突時停止並回報。';$lines[]='完成時請提供修改結果、逐項對照、封存版本與網站呈現驗證。';
    return ['payload'=>$payload,'prompt'=>implode("\n",$lines)];
}

function portal_image_requirement_revision_list(PDO $database,int $documentId): array
{
    if((int)$database->query("SELECT CASE WHEN OBJECT_ID(N'dbo.image_requirement_revisions',N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn()!==1)return [];
    $statement=$database->prepare("SELECT CONVERT(varchar(36),public_id) public_id,source_content_hash,revision_number,requirement_json,prompt_text,item_count,status_code,created_at,completed_at,archived_at FROM dbo.image_requirement_revisions WHERE document_id=? ORDER BY created_at DESC,image_requirement_revision_id DESC");
    $statement->execute([$documentId]);return $statement->fetchAll();
}

function portal_image_requirement_revision_create(PDO $database,array $config,array $user,string $publicId,string $observedHash): array
{
    if(!portal_is_admin($user))throw new RuntimeException('FORBIDDEN');
    $document=portal_get_catalog_document($database,$user,$publicId);
    if($document===null||!portal_is_image_extension((string)$document['extension']))throw new RuntimeException('NOT_FOUND');
    $path=portal_document_file_path($config,$document);$actualHash=strtolower((string)hash_file('sha256',$path));$catalogHash=strtolower((string)$document['content_hash']);
    if(preg_match('/^[0-9a-f]{64}$/D',$observedHash)!==1||!hash_equals($catalogHash,$actualHash)||!hash_equals($actualHash,$observedHash))throw new RuntimeException('CONFLICT');
    $database->beginTransaction();
    try{
        $lock=$database->prepare('SELECT document_id FROM dbo.documents WITH (UPDLOCK,HOLDLOCK) WHERE document_id=?');$lock->execute([(int)$document['document_id']]);
        $annotationsStatement=$database->prepare("SELECT CONVERT(varchar(36),public_id) public_id,x_norm,y_norm,width_norm,height_norm,annotation_type_code,geometry_json,style_json,note_text FROM dbo.image_annotations WITH (UPDLOCK,HOLDLOCK) WHERE document_id=? AND source_content_hash=? AND status_code = 'open' ORDER BY created_at,annotation_id");
        $annotationsStatement->execute([(int)$document['document_id'],$actualHash]);$annotations=$annotationsStatement->fetchAll();
        if(!$annotations)throw new RuntimeException('NO_OPEN_ANNOTATIONS');
        foreach($annotations as &$annotation){$annotation['geometry']=json_decode((string)$annotation['geometry_json'],true,32,JSON_THROW_ON_ERROR);$annotation['style']=json_decode((string)$annotation['style_json'],true,32,JSON_THROW_ON_ERROR);unset($annotation['geometry_json'],$annotation['style_json']);}unset($annotation);
        $binding=portal_image_build_signed_binding($config,$document,$actualHash,$annotations,$path);
        $built=portal_image_requirement_build($document,$actualHash,$annotations,$binding);
        $revisionStatement=$database->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM dbo.image_requirement_revisions WITH (UPDLOCK,HOLDLOCK) WHERE document_id=? AND source_content_hash=?');$revisionStatement->execute([(int)$document['document_id'],$actualHash]);$revision=(int)$revisionStatement->fetchColumn();
        $json=json_encode($built['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $insert=$database->prepare("INSERT INTO dbo.image_requirement_revisions(document_id,source_content_hash,revision_number,requirement_json,prompt_text,item_count,created_by_user_id) OUTPUT CONVERT(varchar(36),inserted.public_id) VALUES(?,?,?,?,?,?,?)");
        $insert->execute([(int)$document['document_id'],$actualHash,$revision,$json,$built['prompt'],count($annotations),(int)$user['user_id']]);$revisionId=(string)$insert->fetchColumn();
        portal_document_audit($database,'image_requirement_revision_create','accepted',(int)$user['user_id'],(int)$document['document_id'],'Revision '.$revision);
        $database->commit();
        return ['public_id'=>$revisionId,'source_content_hash'=>$actualHash,'revision_number'=>$revision,'requirement_json'=>$json,'prompt_text'=>$built['prompt'],'item_count'=>count($annotations),'status_code'=>'ready','created_at'=>gmdate('Y-m-d\TH:i:s'),'completed_at'=>null,'archived_at'=>null];
    }catch(Throwable $e){if($database->inTransaction())$database->rollBack();throw $e;}
}

function portal_image_requirement_revision_locked_context(PDO $database,array $config,string $revisionId): array
{
    $statement=$database->prepare("SELECT r.document_id,LOWER(r.source_content_hash) source_content_hash,r.status_code,r.completed_at,r.archived_at,LOWER(d.content_hash) catalog_content_hash,d.relative_path,d.extension FROM dbo.image_requirement_revisions AS r WITH (UPDLOCK,HOLDLOCK) INNER JOIN dbo.documents AS d WITH (UPDLOCK,HOLDLOCK) ON d.document_id=r.document_id WHERE r.public_id=CONVERT(uniqueidentifier,?) AND d.status_code<>'archived'");
    $statement->execute([$revisionId]);$context=$statement->fetch();if($context===false)throw new RuntimeException('NOT_FOUND');
    $path=portal_document_file_path($config,$context);$actualHash=strtolower((string)hash_file('sha256',$path));$catalogHash=strtolower((string)$context['catalog_content_hash']);
    if(preg_match('/^[0-9a-f]{64}$/D',$actualHash)!==1||preg_match('/^[0-9a-f]{64}$/D',$catalogHash)!==1||!hash_equals($catalogHash,$actualHash))throw new RuntimeException('CONFLICT');
    $context['actual_content_hash']=$actualHash;return $context;
}

function portal_image_requirement_revision_is_current_source(array $context): bool
{
    $revisionHash=strtolower((string)($context['source_content_hash']??''));$catalogHash=strtolower((string)($context['catalog_content_hash']??''));$actualHash=strtolower((string)($context['actual_content_hash']??''));
    return preg_match('/^[0-9a-f]{64}$/D',$revisionHash)===1&&preg_match('/^[0-9a-f]{64}$/D',$catalogHash)===1&&preg_match('/^[0-9a-f]{64}$/D',$actualHash)===1&&hash_equals($revisionHash,$catalogHash)&&hash_equals($catalogHash,$actualHash);
}

function portal_image_requirement_revision_set_status(PDO $database,array $config,array $user,string $revisionId): array
{
    if(!portal_is_admin($user))throw new RuntimeException('FORBIDDEN');
    $ownsTransaction=!$database->inTransaction();if($ownsTransaction)$database->beginTransaction();
    try{$context=portal_image_requirement_revision_locked_context($database,$config,$revisionId);if($context['status_code']!=='ready'||$context['archived_at']!==null)throw new RuntimeException('NOT_FOUND');if(!portal_image_requirement_revision_is_current_source($context))throw new RuntimeException('CONFLICT');
        $statement=$database->prepare("UPDATE dbo.image_requirement_revisions SET status_code='completed',completed_by_user_id=?,completed_at=SYSUTCDATETIME() WHERE public_id=CONVERT(uniqueidentifier,?) AND source_content_hash=? AND status_code='ready' AND archived_at IS NULL");$statement->execute([(int)$user['user_id'],$revisionId,$context['source_content_hash']]);if($statement->rowCount()!==1)throw new RuntimeException('CONFLICT');
        $result=$database->prepare("SELECT status_code,completed_at,archived_at FROM dbo.image_requirement_revisions WHERE public_id=CONVERT(uniqueidentifier,?)");$result->execute([$revisionId]);$row=$result->fetch();if($row===false)throw new RuntimeException('NOT_FOUND');if($ownsTransaction)$database->commit();return $row;
    }catch(Throwable $e){if($ownsTransaction&&$database->inTransaction())$database->rollBack();throw $e;}
}

function portal_image_requirement_revision_set_archived(PDO $database,array $config,array $user,string $revisionId,bool $restore): array
{
    if(!portal_is_admin($user))throw new RuntimeException('FORBIDDEN');
    $ownsTransaction=!$database->inTransaction();if($ownsTransaction)$database->beginTransaction();
    try{$context=portal_image_requirement_revision_locked_context($database,$config,$revisionId);if($restore){if($context['archived_at']===null)throw new RuntimeException('NOT_FOUND');$sql="UPDATE dbo.image_requirement_revisions SET archived_by_user_id=NULL,archived_at=NULL WHERE public_id=CONVERT(uniqueidentifier,?) AND archived_at IS NOT NULL";$params=[$revisionId];}
        else{if($context['archived_at']!==null)throw new RuntimeException('NOT_FOUND');if(!$restore&&$context['status_code']==='completed'&&portal_image_requirement_revision_is_current_source($context))throw new RuntimeException('CONFLICT');$sql="UPDATE dbo.image_requirement_revisions SET archived_by_user_id=?,archived_at=SYSUTCDATETIME() WHERE public_id=CONVERT(uniqueidentifier,?) AND archived_at IS NULL";$params=[(int)$user['user_id'],$revisionId];}
        $statement=$database->prepare($sql);$statement->execute($params);if($statement->rowCount()!==1)throw new RuntimeException('CONFLICT');if($ownsTransaction)$database->commit();return ['public_id'=>$revisionId,'archived'=>!$restore];
    }catch(Throwable $e){if($ownsTransaction&&$database->inTransaction())$database->rollBack();throw $e;}
}

function portal_render_image_review(PDO $database, array $config, array $user, string $publicId): void
{
    $document = portal_get_catalog_document($database, $user, $publicId);
    if ($document === null || !portal_is_image_extension((string) $document['extension'])) {
        http_response_code(404);
        portal_render_page('圖片無法開啟', '<section class="panel narrow"><h1>圖片無法開啟</h1><p>找不到圖片或沒有讀取權限。</p></section>', $user);
        return;
    }
    $path = portal_document_file_path($config, $document);
    $actualHash = strtolower((string) hash_file('sha256', $path));
    $catalogHash = strtolower((string) ($document['content_hash'] ?? ''));
    if ($catalogHash === '' || !hash_equals($catalogHash, $actualHash)) {
        throw new RuntimeException('圖片來源已變更，請先重新索引。');
    }
    $annotations = portal_image_annotations($database, (int) $document['document_id'], $catalogHash);
    $annotationJson = portal_e(json_encode($annotations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $revisions=portal_image_requirement_revision_list($database,(int)$document['document_id']);
    $revisionJson=portal_e(json_encode($revisions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $currentImageUrl=portal_url('image_asset',['id'=>(string)$document['public_id'],'expected_hash'=>$catalogHash]);
    $snapshotHtml='<section class="site-feedback-snapshot-compare image-current-result" aria-label="目前正式圖片">'
        .'<article class="site-feedback-snapshot-card"><h3>目前正式圖片</h3><img src="'.portal_e($currentImageUrl).'" alt="目前正式圖片"><p class="muted">AI 修改完成後會直接顯示目前正式圖片；被替換的原圖會保存於圖片版本封存。</p></article></section>';
    $imageVersionConfig=portal_document_version_config_for_document($config,$document);
    $imageVersionPanel='';
    if(portal_is_admin($user)&&($imageVersionConfig['document_version_enabled']??false)===true){
        try{
            portal_sync_document_version_operation_results($database,$imageVersionConfig);
            $versionNumbers=portal_get_document_version_numbers($database,(int)$document['document_id']);
            $versionOperations=portal_list_document_version_operations($database,(int)$document['document_id']);
            $imageVersionPanel=portal_render_document_version_panel($imageVersionConfig,$document,$user,$versionNumbers,$versionOperations);
        }catch(Throwable $versionException){
            error_log('TWWATER image source version panel failed: '.$versionException->getMessage());
            $imageVersionPanel='<section class="panel version-panel"><h2>圖片版本與還原</h2><p class="muted">圖片版本資訊目前無法讀取，請稍後再試。</p></section>';
        }
    }
    $activeRevisionCount=count(array_filter($revisions,static fn(array $row):bool=>$row['archived_at']===null));
    $archivedRevisionCount=count($revisions)-$activeRevisionCount;
    $canAnnotate = portal_can_edit_documents($user);
    $toolbar='<div class="site-feedback-toolbar" aria-label="圖片標註工具列">'
        . '<div class="site-feedback-toolbar-group"><label class="site-feedback-page-picker">目前圖片<select aria-label="目前圖片"><option>' . portal_e((string) $document['title']) . '</option></select></label></div>'
        . '<div class="site-feedback-toolbar-group" role="toolbar" aria-label="圖片標註工具">';
    foreach([['browse','瀏覽圖片'],['select','移動／調整'],['rectangle','框選'],['arrow','箭頭'],['highlight','螢光'],['text','文字'],['number','編號']] as $tool) {
        $toolbar.='<button type="button" class="site-feedback-tool-button" data-image-tool="'.$tool[0].'" aria-pressed="'.($tool[0]==='browse'?'true':'false').'">'.$tool[1].'</button>';
    }
    $toolbar.='</div><div class="site-feedback-toolbar-group"><button type="button" class="site-feedback-tool-button" data-image-action="undo">復原</button><button type="button" class="site-feedback-tool-button" data-image-action="redo">重做</button><button type="button" class="site-feedback-tool-button is-danger" data-image-action="delete-selection">刪除</button></div></div>';
    $content = '<div class="image-review-page">'
        . '<section class="site-feedback-intro"><div><p class="eyebrow">VISUAL REQUIREMENTS</p><h1>AI 圖像／資訊圖表修改需求</h1><p>可直接在 AI 圖像／資訊圖表上框選、畫箭頭、螢光、文字或編號，再逐條整理修改需求；AI 修改通過驗證後會先封存目前原圖，再直接更新正式圖片。</p></div><div class="site-feedback-intro-actions"><button type="button" class="secondary-button" data-image-revision-history>修改歷程</button><button type="button" class="primary-button" data-image-new-request title="圖片工作區會保留既有已儲存內容，並開始新增一項修改需求。">新增需求批次</button></div></section>'
        . '<section data-image-review data-document-id="' . portal_e((string) $document['public_id']) . '" data-document-title="' . portal_e((string) $document['title']) . '" data-relative-path="' . portal_e((string) $document['relative_path']) . '" data-source-hash="' . portal_e($catalogHash) . '" data-fabric-module-url="assets/vendor/fabric/index.min.mjs"'
        . ' data-save-url="' . portal_url('image_annotations_save', ['id' => (string) $document['public_id']]) . '" data-status-url="' . portal_url('image_annotation_status') . '" data-revision-create-url="'.portal_url('image_requirement_revision_create').'" data-revision-status-url="'.portal_url('image_requirement_revision_status').'" data-revision-archive-url="'.portal_url('image_requirement_revision_archive').'" data-csrf-token="' . portal_e(portal_csrf_token()) . '" data-user-id="' . portal_e((string) $user['user_id']) . '" data-can-annotate="' . ($canAnnotate ? '1' : '0') . '" data-can-manage-all="' . (portal_is_admin($user) ? '1' : '0') . '" data-annotations="' . $annotationJson . '" data-revisions="'.$revisionJson.'">'
        . $toolbar
        . '<div class="site-feedback-workspace"><section class="site-feedback-stage-column"><div class="image-stage-scroll site-feedback-live-stage"><div class="image-annotation-stage" data-image-stage><img src="' . portal_url('image_asset', ['id' => (string) $document['public_id'], 'expected_hash' => $catalogHash]) . '" alt="' . portal_e((string) $document['title']) . '" data-image-source><canvas data-image-canvas aria-label="圖片標註層"></canvas></div></div><p class="site-feedback-stage-status" data-image-stage-status>修改內容：中央是目前正式圖片；框線與文字是保存的修改指引。</p></section>'
        . '<aside class="site-feedback-review-panel"><div class="site-feedback-panel-heading"><h2>AI 圖像／資訊圖表畫面調整</h2><span class="site-feedback-status" data-image-selected-revision-status>編輯中</span></div><p class="muted">可直接在右側填寫每一項修改內容並儲存；完整標註與進階資料可在浮動視窗中檢視或修改。</p>'
        . '<section class="site-feedback-batch-preview"><nav class="site-feedback-batch-preview-tabs" aria-label="切換需求內容檢視"><a href="#" class="is-active" data-image-view="draft">修改內容</a><a href="#" data-image-view="result">修改結果</a></nav><div class="site-feedback-batch-preview-body">'
        . $snapshotHtml
        . '<h3 class="site-feedback-summary-title" data-image-summary-title>修改說明（可直接填寫）</h3><ol class="image-annotation-list site-feedback-inline-requirements" data-image-annotation-list></ol><p class="muted" data-image-result-empty hidden>尚未產生修改結果；AI完成並通過驗證後，會先封存目前原圖，再直接更新正式圖片。</p>'
        . '<div class="site-feedback-summary-actions"><button type="button" class="secondary-button" data-image-detail-open>檢視／修改細部內容</button>'
        . ($canAnnotate ? '<button type="button" class="secondary-button" data-image-save>儲存內容</button>' : '<button type="button" class="secondary-button" disabled>儲存內容</button>')
        . '<button type="button" class="primary-button site-feedback-analysis-submit" data-image-revision-create disabled>整理精確規格</button><button type="button" class="secondary-button" data-image-revision-copy disabled>請先整理精確規格</button><button type="button" class="secondary-button" data-image-revision-download disabled>下載修改需求 JSON</button><button type="button" class="secondary-button" data-image-revision-complete disabled hidden>確認修改已完成</button></div>'
        . '<span class="image-requirement-status" data-image-review-status data-image-requirement-status role="status" aria-live="polite">請先儲存修改內容，再整理精確規格。</span><details class="image-requirement-details" data-image-revision-details><summary>技術定位資料與完整 AI 提示詞預覽（一般不需閱讀）</summary><p data-image-revision-identity></p><textarea readonly data-image-requirement-preview aria-label="已選 Revision 完整 AI 修改提示詞"></textarea></details></div></section></aside></div>'
        . '<dialog class="site-feedback-batch-dialog" data-image-revision-history-dialog aria-labelledby="image-revision-history-title"><div class="site-feedback-batch-dialog-heading"><div><p class="eyebrow">REQUIREMENT HISTORY</p><h2 id="image-revision-history-title">修改歷程</h2></div><button type="button" class="secondary-button" data-image-revision-history-close>關閉</button></div><nav class="site-feedback-history-view-switch" aria-label="修改歷程顯示範圍"><a href="#" class="is-active" data-image-history-view="active">目前歷程（'.$activeRevisionCount.'）</a><a href="#" data-image-history-view="archived">封存資料（'.$archivedRevisionCount.'）</a></nav><p class="muted">最近更新在上；封存後會從目前歷程隱藏，Revision 及需求內容仍完整保留。</p><p class="site-feedback-history-feedback" data-image-revision-history-status role="status" aria-live="polite">請按「選取此 Revision」後，再回到右側確認修改完成；無截圖的按鈕會明確標示為不可用。</p><div class="site-feedback-batch-list" data-image-revision-history-list></div></dialog>'
        . '<dialog class="site-feedback-detail-dialog" data-image-detail-dialog aria-labelledby="image-detail-title"><div class="site-feedback-detail-dialog-heading"><div><p class="eyebrow">ANNOTATION DETAILS</p><h2 id="image-detail-title">修改標註細節與 AI 提示</h2></div><button type="button" class="secondary-button" data-image-detail-close>關閉</button></div><p class="muted">在此檢視或編輯完整標註，並預覽 AI 修改提示。</p><ol class="site-feedback-annotation-list" data-image-detail-list></ol><section class="site-feedback-detail-prompt"><p data-image-detail-revision-identity></p><textarea readonly data-image-detail-preview aria-label="完整 AI 修改提示詞預覽"></textarea></section></dialog></section>' . $imageVersionPanel . '</div>';
    portal_render_page('AI 圖像／資訊圖表修改需求', $content, $user, false, 'wide-readable-page site-feedback-page',true);
}

function portal_handle_image_asset(PDO $database, array $config, array $user, string $publicId): never
{
    $document = portal_get_catalog_document($database, $user, $publicId);
    if ($document === null || !portal_is_image_extension((string) $document['extension'])) {
        http_response_code(404); exit;
    }
    $path = portal_document_file_path($config, $document);
    $handle = fopen($path, 'rb');
    if ($handle === false) { http_response_code(404); exit; }
    $expectedHash = strtolower(trim((string) ($_GET['expected_hash'] ?? '')));
    $catalogHash = strtolower((string) ($document['content_hash'] ?? ''));
    $hashContext = hash_init('sha256');
    hash_update_stream($hashContext, $handle);
    $actualHash = strtolower(hash_final($hashContext));
    $statistics = fstat($handle);
    rewind($handle);
    if (preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1
        || $catalogHash === '' || !hash_equals($catalogHash, $expectedHash)
        || !hash_equals($expectedHash, $actualHash) || !is_array($statistics)) {
        fclose($handle);
        http_response_code(409);
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'IMAGE_ASSET_CONFLICT';
        exit;
    }
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    $types=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'];
    $extension=strtolower((string)$document['extension']);
    header('Content-Type: '.($types[$extension]??'application/octet-stream'));
    header('Content-Length: '.(string)$statistics['size']);
    header('Content-Disposition: inline; filename*=UTF-8\'\''.rawurlencode((string)$document['file_name']));
    header('X-Content-Type-Options: nosniff');
    fpassthru($handle); fclose($handle); exit;
}

function portal_image_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 262144) throw new RuntimeException('標註資料過大。');
    $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('標註資料格式不正確。');
    return $value;
}

function portal_image_json(array $payload, int $status = 200): never
{
    http_response_code($status); header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}

function portal_image_requirement_revision_request(PDO $database,array $config,array $user,string $operation): never
{
    try{
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('METHOD');
        $provided=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');if($provided===''||!hash_equals(portal_csrf_token(),$provided))throw new RuntimeException('CSRF');
        $payload=portal_image_read_json();
        if($operation==='create'){$id=(string)($payload['document_id']??'');$hash=strtolower((string)($payload['source_hash']??''));$revision=portal_image_requirement_revision_create($database,$config,$user,$id,$hash);portal_image_json(['ok'=>true,'revision'=>$revision]);}
        $revisionId=strtolower(trim((string)($payload['revision_id']??'')));if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$revisionId))throw new RuntimeException('INVALID');
        if($operation==='status'){$revision=portal_image_requirement_revision_set_status($database,$config,$user,$revisionId);portal_image_json(['ok'=>true,'revision'=>$revision]);}
        $restore=($payload['restore']??false)===true;$revision=portal_image_requirement_revision_set_archived($database,$config,$user,$revisionId,$restore);portal_image_json(['ok'=>true,'revision'=>$revision]);
    }catch(Throwable $e){$status=in_array($e->getMessage(),['FORBIDDEN','CSRF'],true)?403:($e->getMessage()==='CONFLICT'?409:400);portal_image_json(['ok'=>false,'error'=>'圖片修改需求無法更新，請重新整理後再試。'],$status);}
}
function portal_handle_image_requirement_revision_create(PDO $database,array $config,array $user): never{portal_image_requirement_revision_request($database,$config,$user,'create');}
function portal_handle_image_requirement_revision_status(PDO $database,array $config,array $user): never{portal_image_requirement_revision_request($database,$config,$user,'status');}
function portal_handle_image_requirement_revision_archive(PDO $database,array $config,array $user): never{portal_image_requirement_revision_request($database,$config,$user,'archive');}

function portal_handle_image_annotations_save(PDO $database, array $config, array $user, string $publicId): never
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('METHOD');
        if (!portal_can_edit_documents($user)) throw new RuntimeException('FORBIDDEN');
        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($provided === '' || !hash_equals(portal_csrf_token(), $provided)) throw new RuntimeException('CSRF');
        $document = portal_get_catalog_document($database, $user, $publicId);
        if ($document === null || !portal_is_image_extension((string) $document['extension'])) throw new RuntimeException('NOT_FOUND');
        $path = portal_document_file_path($config, $document);
        $hash = strtolower((string) hash_file('sha256', $path));
        if (!hash_equals(strtolower((string) $document['content_hash']), $hash)) throw new RuntimeException('CONFLICT');
        $payload = portal_image_read_json();
        $observedHash = strtolower(trim((string) ($payload['source_hash'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/D', $observedHash) !== 1 || !hash_equals($hash, $observedHash)) throw new RuntimeException('CONFLICT');
        $items = $payload['annotations'] ?? null;
        if (!is_array($items) || count($items) > PORTAL_IMAGE_ANNOTATION_MAX_ITEMS) throw new RuntimeException('INVALID_ANNOTATIONS');
        $normalized = [];
        $allowedTypes=['rectangle','arrow','highlight','text','number'];
        foreach ($items as $item) {
            if (!is_array($item)) throw new RuntimeException('INVALID_ANNOTATION');
            $annotationId = strtolower(trim((string) ($item['public_id'] ?? '')));
            if ($annotationId !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $annotationId)) {
                throw new RuntimeException('INVALID_ANNOTATION');
            }
            $x=(float)($item['x']??-1); $y=(float)($item['y']??-1); $w=(float)($item['width']??0); $h=(float)($item['height']??0);
            $note=portal_trim_text((string)($item['note']??''),1000);
            if (!is_finite($x)||!is_finite($y)||!is_finite($w)||!is_finite($h)||$x<0||$y<0||$w<=0||$h<=0||$x+$w>1.000001||$y+$h>1.000001||$note==='') throw new RuntimeException('INVALID_ANNOTATION');
            $type=strtolower((string)($item['annotation_type']??'rectangle'));
            if(!in_array($type,$allowedTypes,true)) throw new RuntimeException('INVALID_ANNOTATION');
            $geometry=portal_image_clean_geometry($type,$item['geometry']??[],$x,$y,$w,$h);
            $style=portal_image_clean_style($item['style']??[]);
            $normalized[]=['id'=>$annotationId,'x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h,'note'=>$note,'type'=>$type,
                'geometry'=>json_encode($geometry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                'style'=>json_encode($style,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
        }
        $database->beginTransaction();
        $existingStatement = $database->prepare(
            "SELECT LOWER(CONVERT(varchar(36), public_id)) AS public_id
             FROM dbo.image_annotations WITH (UPDLOCK, HOLDLOCK)
             WHERE document_id = ? AND source_content_hash = ? AND created_by_user_id = ? AND status_code = 'open'"
        );
        $existingStatement->execute([(int)$document['document_id'],$hash,(int)$user['user_id']]);
        $existing = array_fill_keys(array_map('strval', array_column($existingStatement->fetchAll(), 'public_id')), true);
        $update = $database->prepare(
            "UPDATE dbo.image_annotations
             SET x_norm = ?, y_norm = ?, width_norm = ?, height_norm = ?, note_text = ?,
                 annotation_type_code = ?, geometry_json = ?, style_json = ?,
                 updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE public_id = CONVERT(uniqueidentifier, ?) AND document_id = ? AND source_content_hash = ?
               AND created_by_user_id = ? AND status_code = 'open'"
        );
        $insert=$database->prepare("INSERT INTO dbo.image_annotations(document_id,source_content_hash,x_norm,y_norm,width_norm,height_norm,note_text,annotation_type_code,geometry_json,style_json,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $kept = [];
        foreach($normalized as $row) {
            if ($row['id'] !== '') {
                if (!isset($existing[$row['id']])) throw new RuntimeException('CONFLICT');
                $update->execute([$row['x'],$row['y'],$row['width'],$row['height'],$row['note'],$row['type'],$row['geometry'],$row['style'],(int)$user['user_id'],$row['id'],(int)$document['document_id'],$hash,(int)$user['user_id']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('CONFLICT');
                $kept[$row['id']] = true;
            } else {
                $insert->execute([(int)$document['document_id'],$hash,$row['x'],$row['y'],$row['width'],$row['height'],$row['note'],$row['type'],$row['geometry'],$row['style'],(int)$user['user_id'],(int)$user['user_id']]);
            }
        }
        $delete = $database->prepare(
            "DELETE FROM dbo.image_annotations
             WHERE public_id = CONVERT(uniqueidentifier, ?) AND document_id = ? AND source_content_hash = ?
               AND created_by_user_id = ? AND status_code = 'open'"
        );
        foreach (array_keys($existing) as $existingId) {
            if (!isset($kept[$existingId])) $delete->execute([$existingId,(int)$document['document_id'],$hash,(int)$user['user_id']]);
        }
        portal_document_audit($database,'image_annotations_save','accepted',(int)$user['user_id'],(int)$document['document_id'],count($normalized).' annotations');
        $database->commit();
        portal_image_json(['ok'=>true,'annotations'=>portal_image_annotations($database,(int)$document['document_id'],$hash)]);
    } catch (Throwable $e) {
        if ($database->inTransaction()) $database->rollBack();
        $status = in_array($e->getMessage(),['FORBIDDEN','CSRF'],true)?403:(in_array($e->getMessage(),['CONFLICT'],true)?409:400);
        portal_image_json(['ok'=>false,'error'=>'圖片標註無法儲存，請重新整理後再試。'],$status);
    }
}

function portal_handle_image_annotation_status(PDO $database, array $config, array $user): never
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('METHOD');
        if (!portal_can_edit_documents($user)) throw new RuntimeException('FORBIDDEN');
        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($provided === '' || !hash_equals(portal_csrf_token(), $provided)) throw new RuntimeException('CSRF');
        $payload = portal_image_read_json();
        $annotationId = strtolower(trim((string) ($payload['annotation_id'] ?? '')));
        $statusCode = strtolower(trim((string) ($payload['status_code'] ?? '')));
        $observedHash = strtolower(trim((string) ($payload['source_hash'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $annotationId)
            || !in_array($statusCode, ['open', 'resolved'], true)
            || preg_match('/^[0-9a-f]{64}$/D', $observedHash) !== 1) {
            throw new RuntimeException('INVALID_STATUS');
        }
        $statement = $database->prepare(
            "SELECT a.document_id, a.source_content_hash, a.created_by_user_id,
                    CONVERT(varchar(36), d.public_id) AS document_public_id
             FROM dbo.image_annotations a
             INNER JOIN dbo.documents d ON d.document_id = a.document_id
             WHERE a.public_id = CONVERT(uniqueidentifier, ?)"
        );
        $statement->execute([$annotationId]);
        $annotation = $statement->fetch();
        if ($annotation === false) throw new RuntimeException('NOT_FOUND');
        $document = portal_get_catalog_document($database, $user, (string) $annotation['document_public_id']);
        if ($document === null || !portal_is_image_extension((string) $document['extension'])) throw new RuntimeException('NOT_FOUND');
        if (!portal_is_admin($user) && (int) $annotation['created_by_user_id'] !== (int) $user['user_id']) throw new RuntimeException('FORBIDDEN');
        $path = portal_document_file_path($config, $document);
        $hash = strtolower((string) hash_file('sha256', $path));
        if (!hash_equals($observedHash, $hash)
            || !hash_equals(strtolower((string) $annotation['source_content_hash']), $hash)
            || !hash_equals(strtolower((string) $document['content_hash']), $hash)) {
            throw new RuntimeException('CONFLICT');
        }
        $database->beginTransaction();
        $update = $database->prepare(
            "UPDATE dbo.image_annotations
             SET status_code = ?, updated_by_user_id = ?, updated_at = SYSUTCDATETIME()
             WHERE public_id = CONVERT(uniqueidentifier, ?) AND source_content_hash = ?"
        );
        $update->execute([$statusCode, (int) $user['user_id'], $annotationId, $hash]);
        if ($update->rowCount() !== 1) throw new RuntimeException('CONFLICT');
        portal_document_audit($database, 'image_annotation_status', 'accepted', (int) $user['user_id'], (int) $document['document_id'], $annotationId . ':' . $statusCode);
        $database->commit();
        portal_image_json(['ok' => true, 'status_code' => $statusCode]);
    } catch (Throwable $e) {
        if ($database->inTransaction()) $database->rollBack();
        $status = in_array($e->getMessage(), ['FORBIDDEN', 'CSRF'], true) ? 403
            : ($e->getMessage() === 'CONFLICT' ? 409 : 400);
        portal_image_json(['ok' => false, 'error' => '標註狀態無法更新，請重新整理後再試。'], $status);
    }
}

function portal_image_acquire_export_locks(string $root, int $userId): array
{
    $lockRoot = $root . DIRECTORY_SEPARATOR . 'image-export-locks';
    if ((!is_dir($lockRoot) && !mkdir($lockRoot, 0700, true) && !is_dir($lockRoot)) || is_link($lockRoot)) {
        throw new RuntimeException('圖片匯出鎖定空間無法使用。');
    }
    $userLock = fopen($lockRoot . DIRECTORY_SEPARATOR . 'user-' . $userId . '.lock', 'c+b');
    if ($userLock === false || !flock($userLock, LOCK_EX | LOCK_NB)) {
        if (is_resource($userLock)) fclose($userLock);
        throw new RuntimeException('同一帳號已有圖片匯出工作執行中。');
    }
    for ($slot = 0; $slot < 2; $slot++) {
        $globalLock = fopen($lockRoot . DIRECTORY_SEPARATOR . 'global-' . $slot . '.lock', 'c+b');
        if ($globalLock !== false && flock($globalLock, LOCK_EX | LOCK_NB)) return [$userLock, $globalLock];
        if (is_resource($globalLock)) fclose($globalLock);
    }
    flock($userLock, LOCK_UN); fclose($userLock);
    throw new RuntimeException('圖片匯出服務忙碌中，請稍後再試。');
}

function portal_image_cleanup_export_job(string $jobDir): void
{
    if (!is_dir($jobDir) || is_link($jobDir)) return;
    foreach (glob($jobDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file) && !is_link($file)) @unlink($file);
    }
    @rmdir($jobDir);
}

function portal_validate_image_export_output(string $path, string $format, int $expectedItems, array $config): bool
{
    if (!is_file($path) || is_link($path)) return false;
    $executor=(string)($config['image_export_python']??'');
    $script=dirname(__DIR__).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'image_exporter.py';
    if($executor===''||!is_file($executor)||is_link($executor)) return false;
    $command = str_ends_with(strtolower($executor), '.exe') && str_contains(strtolower(basename($executor)), 'image_exporter')
        ? [$executor,'--validate-output',$path,$format,(string)$expectedItems]
        : [$executor,$script,'--validate-output',$path,$format,(string)$expectedItems];
    try { portal_staging_run_process($command,dirname(__DIR__),30); }
    catch(Throwable) { return false; }
    if ($format === 'gif') {
        $details = @getimagesize($path);
        return is_array($details) && (string) ($details['mime'] ?? '') === 'image/gif';
    }
    if ($format === 'pdf') {
        $handle = fopen($path, 'rb');
        if ($handle === false) return false;
        $head = fread($handle, 5);
        $size = filesize($path);
        if ($size === false || $size < 10 || fseek($handle, max(0, $size - 2048)) !== 0) { fclose($handle); return false; }
        $tail = stream_get_contents($handle); fclose($handle);
        return $head === '%PDF-' && str_contains((string) $tail, '%%EOF');
    }
    if ($format === 'pptx') {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) return false;
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('ppt/presentation.xml') !== false;
        for ($index = 1; $valid && $index <= $expectedItems; $index++) {
            $valid = $zip->locateName('ppt/slides/slide' . $index . '.xml') !== false
                && $zip->locateName('ppt/media/image' . $index . '.png') !== false;
        }
        $zip->close(); return $valid;
    }
    return false;
}

function portal_handle_image_export(PDO $database, array $config, array $user): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    portal_assert_csrf();
    $ids = array_values(array_unique(array_map('strval', (array)($_POST['document_ids'] ?? []))));
    $format = strtolower((string)($_POST['format'] ?? ''));
    if (!in_array($format,['gif','pdf','pptx'],true) || count($ids)<1 || count($ids)>PORTAL_IMAGE_EXPORT_MAX_ITEMS) throw new RuntimeException('匯出選擇不正確。');
    $root=portal_staging_root($config);
    if(!is_writable($root)) throw new RuntimeException('私有匯出工作區尚未就緒。');
    $exportRoot=$root.DIRECTORY_SEPARATOR.'image-exports';
    if((!is_dir($exportRoot)&&!mkdir($exportRoot,0700,true)&&!is_dir($exportRoot))||is_link($exportRoot)) throw new RuntimeException('私有匯出工作區尚未就緒。');
    $resolvedExportRoot=realpath($exportRoot);
    if($resolvedExportRoot===false||!str_starts_with(strtolower($resolvedExportRoot),strtolower($root.DIRECTORY_SEPARATOR))) throw new RuntimeException('私有匯出工作區尚未就緒。');
    @set_time_limit(150); ignore_user_abort(true);
    $locks=portal_image_acquire_export_locks($root,(int)$user['user_id']);
    $releaseLocks=static function() use (&$locks): void {
        foreach(array_reverse($locks) as $lock) { if(is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }
        $locks=[];
    };
    register_shutdown_function($releaseLocks);
    $items=[]; $documentIds=[]; $totalSourceBytes=0; $totalAnnotations=0;
    foreach($ids as $id) {
        $document=portal_get_catalog_document($database,$user,$id);
        if($document===null||!portal_is_image_extension((string)$document['extension'])) throw new RuntimeException('圖片不存在或沒有權限。');
        $path=portal_document_file_path($config,$document);
        $sourceBytes=filesize($path);
        if($sourceBytes===false) throw new RuntimeException('圖片無法讀取。');
        $totalSourceBytes += (int)$sourceBytes;
        if($totalSourceBytes>PORTAL_IMAGE_EXPORT_MAX_SOURCE_BYTES) throw new RuntimeException('所選圖片總容量超過匯出上限。');
        $hash=strtolower((string)hash_file('sha256',$path));
        if(!hash_equals(strtolower((string)$document['content_hash']),$hash)) throw new RuntimeException('圖片版本已變更，請重新索引。');
        $openAnnotations = array_values(array_filter(
            portal_image_annotations($database,(int)$document['document_id'],$hash),
            static fn(array $annotation): bool => (string) ($annotation['status_code'] ?? '') === 'open'
        ));
        $totalAnnotations += count($openAnnotations);
        if($totalAnnotations>PORTAL_IMAGE_EXPORT_MAX_ANNOTATIONS) throw new RuntimeException('所選圖片標註數超過匯出上限。');
        $annotations=array_map(static fn(array $a):array=>['x'=>(float)$a['x_norm'],'y'=>(float)$a['y_norm'],'width'=>(float)$a['width_norm'],'height'=>(float)$a['height_norm'],'note'=>(string)$a['note_text'],'annotation_type'=>(string)$a['annotation_type_code'],'geometry'=>(array)$a['geometry'],'style'=>(array)$a['style']],$openAnnotations);
        $items[]=['path'=>$path,'expected_hash'=>$hash,'extension'=>(string)$document['extension'],'title'=>(string)$document['title'],'annotations'=>$annotations]; $documentIds[]=(int)$document['document_id'];
    }
    $job=bin2hex(random_bytes(16)); $jobDir=$resolvedExportRoot.DIRECTORY_SEPARATOR.$job;
    $manifest=$jobDir.DIRECTORY_SEPARATOR.'manifest.json'; $output=$jobDir.DIRECTORY_SEPARATOR.'images.'.$format;
    try {
        if(!mkdir($jobDir,0700,true)&&!is_dir($jobDir)) throw new RuntimeException('無法建立私有匯出工作區。');
        foreach($items as $index=>&$item) {
            $immutable_source=$jobDir.DIRECTORY_SEPARATOR.'source-'.($index+1).'.'.$item['extension'];
            if(!copy($item['path'],$immutable_source)||is_link($immutable_source)) throw new RuntimeException('無法建立圖片匯出快照。');
            $snapshotHash=strtolower((string)hash_file('sha256',$immutable_source));
            if(!hash_equals((string)$item['expected_hash'],$snapshotHash)) throw new RuntimeException('圖片版本已變更，請重試。');
            $item['path']=$immutable_source;
            unset($item['extension']);
        }
        unset($item);
        file_put_contents($manifest,json_encode(['format'=>$format,'include_annotations'=>isset($_POST['include_annotations']),'frame_duration_ms'=>(int)($_POST['frame_duration_ms']??1500),'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
        $executor=(string)($config['image_export_python']??''); $script=dirname(__DIR__).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'image_exporter.py';
        if($executor===''||!is_file($executor)) throw new RuntimeException('圖片匯出工具尚未設定。');
        $command = str_ends_with(strtolower($executor), '.exe') && str_contains(strtolower(basename($executor)), 'image_exporter')
            ? [$executor,$manifest,$output]
            : [$executor,$script,$manifest,$output];
        if(count($command)===4&&!is_file($script)) throw new RuntimeException('圖片匯出工具尚未設定。');
        $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
        if(!is_resource($process)) throw new RuntimeException('圖片匯出工具無法啟動。');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $observedExit = null; $timedOut = false; $deadline = microtime(true) + 120.0;
        do {
            $stdout .= (string) stream_get_contents($pipes[1], 4096);
            $stderr .= (string) stream_get_contents($pipes[2], 4096);
            if (strlen($stdout) > 4096) $stdout = substr($stdout, -4096);
            if (strlen($stderr) > 4096) $stderr = substr($stderr, -4096);
            $processStatus = proc_get_status($process);
            if (!$processStatus['running']) { $observedExit = (int) $processStatus['exitcode']; break; }
            if (microtime(true) >= $deadline) {
                $timedOut = true; proc_terminate($process);
                usleep(250000);
                $processStatus = proc_get_status($process);
                if ($processStatus['running']) proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]); $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $closedExit = proc_close($process);
        $exit = $observedExit !== null && $observedExit >= 0 ? $observedExit : $closedExit;
        $outputBytes = is_file($output) ? filesize($output) : false;
        if($timedOut||$exit!==0||$outputBytes===false||$outputBytes<1||$outputBytes>PORTAL_IMAGE_EXPORT_MAX_OUTPUT_BYTES||!portal_validate_image_export_output($output,$format,count($items),$config)) { error_log('Image export failed: '.($timedOut?'timeout ': '').substr((string)$stderr,0,500)); throw new RuntimeException('圖片匯出失敗。'); }
        foreach($documentIds as $documentId) portal_document_audit($database,'image_export_'.$format,'accepted',(int)$user['user_id'],$documentId,count($items).' images');
        $types=['gif'=>'image/gif','pdf'=>'application/pdf','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache');
        header('Content-Type: '.$types[$format]); header('Content-Length: '.filesize($output)); header('Content-Disposition: attachment; filename="twwater-images-'.gmdate('Ymd-His').'.'.$format.'"'); header('X-Content-Type-Options: nosniff'); readfile($output);
    } finally {
        portal_image_cleanup_export_job($jobDir);
        $releaseLocks();
    }
    exit;
}
