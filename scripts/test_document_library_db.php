<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function fail_test(string $message): never
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

$database = portal_open_database(portal_config());
$database->beginTransaction();
register_shutdown_function(static function () use ($database): void {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
});
$user = ['role_code' => 'admin'];
$defaultFilters=portal_catalog_filters(['view'=>'stage','sort'=>'modified_desc']);
$defaultFilters['exclude_visual_assets']=true;
$defaultFilters['exclude_internal_markdown']=true;
$defaultDocuments=portal_list_catalog_documents($database,$user,$defaultFilters);
if(array_filter($defaultDocuments,static fn(array $row):bool=>(string)$row['status_code']==='archived')!==[]){
    fail_test('administrator document library exposed archived catalog rows');
}
if(array_filter($defaultDocuments,static fn(array $row):bool=>(string)$row['extension']==='md')!==[]){
    fail_test('general document library exposed internal Markdown files');
}
$adminFilters=portal_admin_catalog_filters([]);
$adminFilters['include_archived']=true;
$adminDocuments=portal_list_catalog_documents($database,$user,$adminFilters);
if(array_filter($adminDocuments,static fn(array $row):bool=>(string)$row['status_code']==='archived')===[]){
    fail_test('administrator management catalog cannot discover archived rows');
}
if(array_filter($adminDocuments,static fn(array $row):bool=>(string)$row['extension']==='md')===[]){
    fail_test('administrator management catalog cannot discover internal Markdown files');
}
$readerDocuments=portal_list_catalog_documents($database,['role_code'=>'reader'],$defaultFilters);
if(array_filter($readerDocuments,static fn(array $row):bool=>(string)$row['status_code']!=='published')!==[]){
    fail_test('reader catalog exposed a non-published row');
}
$directoryFilters=portal_catalog_filters(['view'=>'directory','stage'=>'04','role'=>'output','type'=>'design','sort'=>'name_asc']);
$directoryFilters['exclude_visual_assets']=true;
$directoryFilters['exclude_internal_markdown']=true;
$directoryDocuments=portal_list_catalog_documents($database,$user,$directoryFilters);
$directoryTree=portal_build_document_directory_tree($directoryDocuments);
$collectDirectoryPaths=static function(array $node) use (&$collectDirectoryPaths):array {
    $paths=array_map(static fn(array $row):string=>(string)$row['relative_path'],$node['documents']);
    foreach($node['directories'] as $child){array_push($paths,...$collectDirectoryPaths($child));}
    return $paths;
};
$directoryPaths=$collectDirectoryPaths($directoryTree);
if($directoryFilters['stages']!==[]||$directoryFilters['roles']!==[]||$directoryFilters['types']!==[]){
    fail_test('directory view applied stage, role, or type facets instead of preserving them only as saved state');
}
if(count($directoryPaths)!==count($directoryDocuments)||count(array_unique(array_map('strtolower',$directoryPaths)))!==count($directoryPaths)){
    fail_test('directory view duplicated or omitted a catalog path');
}
if(array_filter($directoryDocuments,static fn(array $row):bool=>(string)$row['extension']==='md'||portal_is_image_extension((string)$row['extension']))!==[]){
    fail_test('directory view exposed an internal Markdown or visual asset');
}
$orderBy = [
    'created_desc' => 'd.created_at DESC, d.document_id DESC',
    'created_asc' => 'd.created_at ASC, d.document_id ASC',
    'modified_desc' => 'd.source_modified_at DESC, d.document_id DESC',
    'modified_asc' => 'd.source_modified_at ASC, d.document_id ASC',
    'name_asc' => 'd.title ASC, d.file_name ASC, d.document_id ASC',
    'name_desc' => 'd.title DESC, d.file_name DESC, d.document_id DESC',
];
foreach ($orderBy as $sort => $expectedOrder) {
    $filters = portal_catalog_filters(['view' => 'stage', 'sort' => $sort]);
    $filters['exclude_visual_assets'] = true;
    $filters['exclude_internal_markdown'] = true;
    $documents = portal_list_catalog_documents($database, $user, $filters);
    $expectedStatement = $database->query(
        'SELECT TOP ' . PORTAL_DOCUMENT_LIST_LIMIT . ' d.document_id FROM dbo.documents d '
        . "WHERE d.status_code <> 'archived' AND d.extension <> 'md' AND d.extension NOT IN ('png','jpg','jpeg','webp','gif') ORDER BY " . $expectedOrder
    );
    $expectedIds = array_map('intval', array_column($expectedStatement->fetchAll(), 'document_id'));
    $actualIds = array_map('intval', array_column($documents, 'document_id'));
    if ($actualIds !== $expectedIds) {
        fail_test("{$sort} results are not monotonic in the requested order");
    }
    foreach ($documents as $document) {
        foreach (['relative_path', 'created_at', 'updated_at', 'source_modified_at'] as $field) {
            if (!array_key_exists($field, $document) || trim((string) $document[$field]) === '') {
                fail_test("{$sort} result is missing {$field}");
            }
        }
        if (str_contains((string) $document['relative_path'], "\\") || str_starts_with((string) $document['relative_path'], '/')) {
            fail_test("{$sort} returned a non-normalized relative path");
        }
    }
}

$stageOnly = portal_catalog_filters(['view' => 'stage', 'stage' => '04', 'role' => 'output', 'sort' => 'modified_desc']);
$stageOnly['exclude_visual_assets'] = true;
$stageOnly['exclude_internal_markdown'] = true;
$craftedStage = portal_catalog_filters(['view' => 'stage', 'stage' => '04', 'role' => 'output', 'type' => 'design', 'sort' => 'modified_desc']);
$craftedStage['exclude_visual_assets'] = true;
$craftedStage['exclude_internal_markdown'] = true;
if (array_column(portal_list_catalog_documents($database, $user, $stageOnly), 'document_id') !== array_column(portal_list_catalog_documents($database, $user, $craftedStage), 'document_id')) {
    fail_test('inactive type filter changed stage-view database results');
}
$typeOnly = portal_catalog_filters(['view' => 'type', 'type' => 'design', 'sort' => 'modified_desc']);
$typeOnly['exclude_visual_assets'] = true;
$typeOnly['exclude_internal_markdown'] = true;
$craftedType = portal_catalog_filters(['view' => 'type', 'type' => 'design', 'stage' => '04', 'role' => 'output', 'sort' => 'modified_desc']);
$craftedType['exclude_visual_assets'] = true;
$craftedType['exclude_internal_markdown'] = true;
if (array_column(portal_list_catalog_documents($database, $user, $typeOnly), 'document_id') !== array_column(portal_list_catalog_documents($database, $user, $craftedType), 'document_id')) {
    fail_test('inactive stage or role filter changed type-view database results');
}

$phaseCodes=array_map('strval',array_column($database->query("SELECT TOP (2) phase_code FROM dbo.ssdlc_phases WHERE is_active=1 ORDER BY sort_order,phase_code")->fetchAll(),'phase_code'));
if(count($phaseCodes)===2){
    $multiStage=portal_catalog_filters(['view'=>'stage','stage'=>$phaseCodes,'role'=>['input','output'],'sort'=>'modified_desc']);
    $multiStage['exclude_visual_assets']=true;
    $multiStage['exclude_internal_markdown']=true;
    $placeholders=implode(',',array_fill(0,count($phaseCodes),'?'));
    $expected=$database->prepare("SELECT TOP ".PORTAL_DOCUMENT_LIST_LIMIT." d.document_id FROM dbo.documents d WHERE d.status_code <> 'archived' AND d.extension <> 'md' AND d.extension NOT IN ('png','jpg','jpeg','webp','gif') AND EXISTS (SELECT 1 FROM dbo.document_phase_roles pr INNER JOIN dbo.ssdlc_phases p ON p.phase_id=pr.phase_id WHERE pr.document_id=d.document_id AND p.phase_code IN ({$placeholders}) AND pr.role_code IN ('input','output')) ORDER BY d.source_modified_at DESC,d.document_id DESC");
    $expected->execute($phaseCodes);
    if(array_map('intval',array_column(portal_list_catalog_documents($database,$user,$multiStage),'document_id'))!==array_map('intval',array_column($expected->fetchAll(),'document_id'))){fail_test('multi-stage/role filters did not use parameterized OR-within-facet semantics');}
}
$typeCodes=array_map('strval',array_column($database->query("SELECT TOP (2) type_code FROM dbo.document_types WHERE is_active=1 ORDER BY sort_order,type_code")->fetchAll(),'type_code'));
if(count($typeCodes)===2){
    $multiType=portal_catalog_filters(['view'=>'type','type'=>$typeCodes,'sort'=>'modified_desc']);
    $multiType['exclude_visual_assets']=true;
    $multiType['exclude_internal_markdown']=true;
    $expected=$database->prepare("SELECT TOP ".PORTAL_DOCUMENT_LIST_LIMIT." d.document_id FROM dbo.documents d WHERE d.status_code <> 'archived' AND d.extension <> 'md' AND d.extension NOT IN ('png','jpg','jpeg','webp','gif') AND EXISTS (SELECT 1 FROM dbo.document_type_links l INNER JOIN dbo.document_types t ON t.document_type_id=l.document_type_id WHERE l.document_id=d.document_id AND t.type_code IN (?,?)) ORDER BY d.source_modified_at DESC,d.document_id DESC");
    $expected->execute($typeCodes);
    if(array_map('intval',array_column(portal_list_catalog_documents($database,$user,$multiType),'document_id'))!==array_map('intval',array_column($expected->fetchAll(),'document_id'))){fail_test('multi-type filters did not use parameterized OR-within-facet semantics');}
}

$database->rollBack();
echo "[OK] document library sort queries, multi-select filtering, inactive-filter isolation, and relative-path fields read successfully.\n";
