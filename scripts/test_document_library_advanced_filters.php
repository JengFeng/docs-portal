<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function advanced_filter_assert(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}
}

$multi=portal_catalog_filters([
    'view'=>'stage',
    'stage'=>['04','02','04','bad',''],
    'role'=>['input','output','owner','input'],
    'type_state'=>['design','report','DROP TABLE','design'],
]);
advanced_filter_assert($multi['stages']===['04','02'],'stage filters must accept multiple valid values, deduplicate, and reject malformed values');
advanced_filter_assert($multi['roles']===['input','output'],'role filters must accept multiple allowlisted values and fail closed');
advanced_filter_assert($multi['type_states']===['design','report'],'saved type filters must remain normalized arrays across views and reject malformed codes');

$scalar=portal_catalog_filters(['view'=>'type','type'=>'design','stage_state'=>'04','role_state'=>'output']);
advanced_filter_assert($scalar['types']===['design'],'legacy scalar query values must remain compatible');
advanced_filter_assert($scalar['stage_states']===['04']&&$scalar['role_states']===['output'],'legacy scalar saved state must normalize to arrays');

$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/app/bootstrap.php');
$css=(string)file_get_contents(dirname(__DIR__).'/assets/app.css');
$filterStart=strpos($index,'function portal_render_library_filters');
$filterEnd=strpos($index,'function portal_render_stage_context',$filterStart);
$filterRenderer=$filterStart!==false&&$filterEnd!==false?substr($index,$filterStart,$filterEnd-$filterStart):'';
advanced_filter_assert(str_contains($filterRenderer,'<details class="library-advanced-filter"')&&str_contains($filterRenderer,'進階查詢'),'document filter conditions must live in a clearly labelled advanced disclosure');
advanced_filter_assert(str_contains($filterRenderer,"portal_render_library_checkbox_group('SSDLC 階段', 'stage'")&&str_contains($filterRenderer,"portal_render_library_checkbox_group('文件角色', 'role'")&&str_contains($filterRenderer,"portal_render_library_checkbox_group('文件種類', 'type'")&&str_contains($index,'portal_e($name) . \'[]"'),'stage, role, and type conditions must render as multi-value checkbox controls');
advanced_filter_assert(!str_contains($filterRenderer,'<select name="stage"')&&!str_contains($filterRenderer,'<select name="role"')&&!str_contains($filterRenderer,'<select name="type"'),'advanced conditions must no longer render as single-select controls');
advanced_filter_assert(str_contains($index,'$advancedOpen =')&&str_contains($index,"? ' open' : ''"),'active advanced conditions must reopen after submission so selected filters remain visible');
advanced_filter_assert(str_contains($index,'portal_render_hidden_values'),'view and sort forms must preserve every selected multi-value filter');
advanced_filter_assert(str_contains($bootstrap,"implode(',',array_fill(0,count(\$types),'?'))")&&str_contains($bootstrap,"implode(',',array_fill(0,count(\$stages),'?'))"),'catalog SQL must bind multi-value filters through generated placeholders');
advanced_filter_assert(str_contains($bootstrap,'array_push($parameters,...$types)')&&str_contains($bootstrap,'array_push($parameters,...$stages)'),'catalog SQL must parameterize every selected value');
advanced_filter_assert(str_contains($css,'.library-advanced-filter::details-content')&&str_contains($css,'transition:'),'advanced disclosure must provide a sliding open/close transition');
advanced_filter_assert(str_contains($css,'.library-filter-option input[type="checkbox"]'),'checkbox filters must have explicit readable styling');
advanced_filter_assert(str_contains($css,'@media (max-width: 420px)')&&str_contains($css,'.library-advanced-grid'),'advanced checkbox filters must have a true-mobile layout rule');

echo "[OK] document-library advanced multi-select filter contracts passed.\n";
