<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
function direct_library_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'twwater-direct-library-'.bin2hex(random_bytes(6));
try {
 mkdir($root,0770,true); mkdir($root.DIRECTORY_SEPARATOR.'會議記錄'); mkdir($root.DIRECTORY_SEPARATOR.'_system');
 file_put_contents($root.DIRECTORY_SEPARATOR.'會議記錄'.DIRECTORY_SEPARATOR.'報告.pdf','pdf');
 file_put_contents($root.DIRECTORY_SEPARATOR.'會議記錄'.DIRECTORY_SEPARATOR.'說明.docx','docx');
 file_put_contents($root.DIRECTORY_SEPARATOR.'圖.png','image');
 file_put_contents($root.DIRECTORY_SEPARATOR.'_system'.DIRECTORY_SEPARATOR.'internal.md','internal');
 $config=['document_root'=>$root,'direct_sync_mode'=>false];
 $documents=portal_direct_library_documents($config,['search'=>'','sort'=>'name_asc']);
 direct_library_assert(count($documents)===2,'general direct library must include only ordinary user documents');
 direct_library_assert(array_column($documents,'relative_path')===['會議記錄/報告.pdf','會議記錄/說明.docx'],'direct files must retain normalized relative paths and name ordering');
 direct_library_assert(portal_direct_document_relative_path((string)$documents[0]['public_id'])==='會議記錄/報告.pdf','direct document ID must round-trip only its relative path');
 $search=portal_direct_library_documents($config,['search'=>'說明','sort'=>'name_asc']);
 direct_library_assert(count($search)===1&&$search[0]['file_name']==='說明.docx','direct library search must use the filename');
 direct_library_assert(portal_direct_document_relative_path('../bad')===null,'invalid direct document ID must fail closed');
 echo "DIRECT_FILESYSTEM_LIBRARY_OK\n";
} finally { if(is_dir($root)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($root);} }
