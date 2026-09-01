<?php
declare(strict_types=1);
function retired_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"[FAIL] $message\n");exit(1);}}
$root=dirname(__DIR__);
$index=(string)file_get_contents($root.'/index.php');
$module=(string)file_get_contents($root.'/app/image_library.php');
$js=(string)file_get_contents($root.'/assets/image-library.js');
foreach(['portal_image_candidate_load','portal_image_candidate_write_decision','portal_handle_image_candidate_asset','portal_handle_image_candidate_decision'] as $symbol){retired_assert(!str_contains($module,$symbol),'retired candidate symbol remains: '.$symbol);}
foreach(['image_candidate_asset','image_candidate_decision'] as $route){retired_assert(!str_contains($index,"'{$route}'"),'retired candidate route remains: '.$route);}
retired_assert(!str_contains($js,'candidateDecisionUrl')&&!str_contains($js,'data-image-candidate'),'retired candidate client workflow remains');
retired_assert(is_file($root.'/scripts/image_source_version_engine.ps1')&&is_file($root.'/scripts/publish_image_output.ps1'),'validated direct image publish engine is missing');
retired_assert(str_contains($module,'圖片版本與還原')&&str_contains($module,'直接更新正式圖片'),'IMAGE REVIEW must expose direct publication and restore history');
fwrite(STDOUT,"[OK] Candidate approval flow retired; validated archive-and-replace flow present.\n");
