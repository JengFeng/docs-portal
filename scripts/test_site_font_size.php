<?php
declare(strict_types=1);
function font_test(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$root=dirname(__DIR__);$bootstrap=(string)file_get_contents($root.'/app/bootstrap.php');$js=(string)file_get_contents($root.'/assets/app.js');$css=(string)file_get_contents($root.'/assets/app.css');
font_test(str_contains($bootstrap,'data-font-size-control')&&str_contains($bootstrap,'data-font-size="small"')&&str_contains($bootstrap,'data-font-size="medium"')&&str_contains($bootstrap,'data-font-size="large"'),'every page must render small, medium, and large font controls');
font_test((bool)preg_match('/\$fontControl\s*=\s*\'<div class="site-font-size-control".*?;\s*if \(\$user !== null\)/s',$bootstrap),'the site-wide font control must also be available before login, not only inside authenticated pages');
font_test(str_contains($bootstrap,'<html lang="zh-Hant" data-font-size="large">'),'large must remain the safe server-rendered default without a flash of small text');
font_test(str_contains($js,"twwater-font-size")&&str_contains($js,"['small', 'medium', 'large']")&&str_contains($js,'document.documentElement.dataset.fontSize'),'font preference must use an allowlisted persistent document-level setting');
foreach(["html[data-font-size='small'] { font-size: 14px; }","html[data-font-size='medium'] { font-size: 16px; }","html[data-font-size='large'] { font-size: 18px; }"] as $rule)font_test(str_contains($css,$rule),'missing global font scale: '.$rule);
font_test(str_contains($css,'body.document-library-page { font-size: 1rem; }')&&str_contains($css,'body.wide-readable-page { font-size: 1rem; }'),'readable page scopes must inherit the selected root size instead of forcing 18px');
font_test(str_contains($css,'.site-font-size-control')&&str_contains($css,'.site-font-size-button[aria-pressed="true"]'),'font controls must have a visible selected state');
echo "[OK] site-wide small, medium, and large font controls are persistent, allowlisted, and default to large.\n";
