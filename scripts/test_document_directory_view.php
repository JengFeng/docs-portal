<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
function directory_view_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
directory_view_assert(function_exists('portal_build_document_directory_tree'),'directory tree builder is missing');
$documents=[
 ['public_id'=>'00000000-0000-4000-8000-000000000001','relative_path'=>'根目錄 文件.pdf','file_name'=>'根目錄 文件.pdf','title'=>'根目錄 文件','extension'=>'pdf'],
 ['public_id'=>'00000000-0000-4000-8000-000000000002','relative_path'=>'doc/子 文件.pptx','file_name'=>'子 文件.pptx','title'=>'子 文件','extension'=>'pptx'],
 ['public_id'=>'00000000-0000-4000-8000-000000000003','relative_path'=>'doc/巢 狀/中文 報告.docx','file_name'=>'中文 報告.docx','title'=>'中文 報告','extension'=>'docx'],
 ['public_id'=>'00000000-0000-4000-8000-000000000002','relative_path'=>'doc/子 文件.pptx','file_name'=>'子 文件.pptx','title'=>'重複資料','extension'=>'pptx'],
];
$tree=portal_build_document_directory_tree($documents);
directory_view_assert(($tree['name']??null)==='/'&&($tree['path']??null)==='','tree must start at a slash-labelled root');
directory_view_assert(count($tree['documents']??[])===1&&($tree['documents'][0]['relative_path']??'')==='根目錄 文件.pdf','root-level file must remain directly under root');
directory_view_assert(isset($tree['directories']['doc']),'root must contain the doc directory node');
$doc=$tree['directories']['doc'];
directory_view_assert(count($doc['documents']??[])===1&&($doc['documents'][0]['relative_path']??'')==='doc/子 文件.pptx','direct child file must remain under doc exactly once');
directory_view_assert(isset($doc['directories']['巢 狀']),'nested directory with spaces and Chinese must be preserved');
directory_view_assert(($doc['directories']['巢 狀']['documents'][0]['relative_path']??'')==='doc/巢 狀/中文 報告.docx','nested file must be assigned to its exact parent');
$collect=static function(array $node) use (&$collect):array{$paths=array_map(static fn(array $row):string=>(string)$row['relative_path'],$node['documents']??[]);foreach($node['directories']??[] as $child){array_push($paths,...$collect($child));}return $paths;};
$paths=$collect($tree);sort($paths,SORT_STRING);directory_view_assert(count($paths)===3&&count(array_unique($paths))===3,'each relative path must appear exactly once');
$adversarialTree=portal_build_document_directory_tree([
 ['relative_path'=>'/absolute/file.pdf','file_name'=>'file.pdf'],
 ['relative_path'=>'\\\\server\\share\\file.pdf','file_name'=>'file.pdf'],
 ['relative_path'=>'C:/drive.pdf','file_name'=>'drive.pdf'],
 ['relative_path'=>'../traversal.pdf','file_name'=>'traversal.pdf'],
 ['relative_path'=>'É/File.pdf','file_name'=>'File.pdf'],
 ['relative_path'=>'é/file.pdf','file_name'=>'file.pdf'],
]);
$adversarialPaths=$collect($adversarialTree);
directory_view_assert(count($adversarialPaths)===1&&mb_strtolower($adversarialPaths[0],'UTF-8')==='é/file.pdf','tree must reject absolute, UNC, drive-qualified, and traversal paths while deduplicating Unicode case variants');
directory_view_assert(portal_build_document_directory_tree([])['documents']===[],'empty input must produce an empty root without error');
directory_view_assert(portal_catalog_filters(['view'=>'directory'])['view']==='directory','directory view must be allowlisted');
directory_view_assert(portal_catalog_filters(['view'=>'invalid'])['view']==='stage','unknown views must still fail closed to stage');
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');$css=(string)file_get_contents(dirname(__DIR__).'/assets/app.css');
foreach(['依網站目錄','目錄階層瀏覽','function portal_render_document_directory_tree','/（根目錄）','directory-empty-state'] as $needle){directory_view_assert(str_contains($index,$needle),'directory view rendering contract missing: '.$needle);}
directory_view_assert(str_contains($index,"\$filters['view'] === 'directory'"),'directory result branch must be explicit');
directory_view_assert(str_contains($index,'<details class="directory-node')&&str_contains($index,'<summary>'),'directory nodes must use native expandable details/summary controls');
directory_view_assert(str_contains($css,'.directory-tree')&&str_contains($css,'.directory-node')&&str_contains($css,'@media (max-width: 420px)'),'directory tree needs scoped responsive styling');
echo "[OK] directory view root, nesting, dedupe, empty-state, routing, native disclosure, and RWD contracts passed.\n";
