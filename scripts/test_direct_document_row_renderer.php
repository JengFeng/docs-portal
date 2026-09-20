<?php
declare(strict_types=1);
$source=(string)file_get_contents(dirname(__DIR__).'/index.php');
if (!str_contains($source,'function portal_render_document_row(')) { fwrite(STDERR,"[FAIL] direct document row renderer is missing\n"); exit(1); }
echo "DIRECT_DOCUMENT_ROW_RENDERER_PRESENT\n";
