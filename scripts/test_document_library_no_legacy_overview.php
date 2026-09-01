<?php
declare(strict_types=1);
function no_legacy_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] {$message}\n");exit(1);}}
$index=(string)file_get_contents(dirname(__DIR__).'/index.php');
$start=strpos($index,'function portal_render_document_library');
$end=strpos($index,'function portal_render_hidden_values',$start);
$renderer=$start!==false&&$end!==false?substr($index,$start,$end-$start):'';
no_legacy_assert($renderer!=='','document library renderer must be locatable');
no_legacy_assert(str_contains($renderer,'portal_render_library_filters($filters, $phases, $types)'),'top advanced query form must remain rendered');
no_legacy_assert(!str_contains($renderer,'portal_render_stage_context('),'document page must no longer render the legacy SSDLC overview query block');
no_legacy_assert(str_contains($renderer,'portal_render_document_results($documents, $filters, portal_is_admin($user))'),'document results must remain rendered');
no_legacy_assert(strpos($renderer,'portal_render_library_filters')<strpos($renderer,'portal_render_document_results'),'advanced query must remain directly above document results without an orphaned block');
echo "[OK] document library renders advanced query directly above results without legacy SSDLC overview.\n";
