<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/image_library.php';

function fail_image_sort(string $message): never { fwrite(STDERR,"[FAIL] {$message}\n"); exit(1); }

$expected=[
    'created_desc'=>'d.created_at DESC, d.document_id DESC','created_asc'=>'d.created_at ASC, d.document_id ASC',
    'modified_desc'=>'d.source_modified_at DESC, d.document_id DESC','modified_asc'=>'d.source_modified_at ASC, d.document_id ASC',
    'name_asc'=>'d.title ASC, d.file_name ASC, d.document_id ASC','name_desc'=>'d.title DESC, d.file_name DESC, d.document_id DESC',
];
foreach($expected as $sort=>$sql){
    if(portal_image_sort(['sort'=>$sort])!==$sort) fail_image_sort("{$sort} was rejected");
    if(portal_image_order_by($sort)!==$sql) fail_image_sort("{$sort} SQL order mapping differs");
}
if(portal_image_sort(['sort'=>'not-allowed'])!=='modified_desc') fail_image_sort('invalid sort did not fail closed');
if(portal_image_sort(['sort'=>['name_desc']])!=='modified_desc') fail_image_sort('array sort did not fail closed');
if(portal_image_order_by('not-allowed')!==$expected['modified_desc']) fail_image_sort('invalid SQL order did not fail closed');
echo "[OK] image asset six-way sort mapping and invalid-sort fallback passed.\n";
