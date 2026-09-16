<?php
declare(strict_types=1);
function rwd_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$css=(string)file_get_contents(dirname(__DIR__).'/assets/app.css');
foreach(['.page-shell{width:min(1760px,calc(100vw - 3rem))','@media(max-width:720px)','.site-nav{order:3;width:100%;min-height:2.7rem;overflow-x:auto}', '.library-filter{grid-template-columns:1fr}', '.document-row{flex-direction:column}', '.admin-health-summary{grid-template-columns:1fr;gap:.2rem}', '@media(max-width:420px)', '.document-row-actions{display:grid;grid-template-columns:1fr 1fr}'] as $rule)rwd_assert(str_contains($css,$rule),'Missing responsive runtime rule: '.$rule);
foreach(['site-feedback','image-review','document-replace','PORTAL_DB_','dbo.'] as $retired)rwd_assert(!str_contains($css,$retired),'Retired runtime styling remains: '.$retired);
echo "RUNTIME_UI_RWD_AND_RETIREMENT_CONTRACT_OK\n";
