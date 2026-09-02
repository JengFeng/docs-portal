<?php
declare(strict_types=1);

function studio_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$rendererPath = $root . '/app/html_studio.php';
$cssPath = $root . '/assets/html-studio.css';
$jsPath = $root . '/assets/html-studio.js';

studio_assert(str_contains($index, "'html_studio'"), 'html_studio route must be allowlisted');
studio_assert(str_contains($index, "if (\$action === 'html_studio')"), 'html_studio route dispatcher missing');
studio_assert(preg_match("/if \(\\\$action === 'html_studio'\)[\\s\\S]{0,220}portal_require_admin\(\\\$database\)/", $index) === 1, 'html_studio must require administrator server-side');
studio_assert(str_contains($bootstrap, "portal_url('html_studio')") && str_contains($bootstrap, 'HTML 工作室'), 'administrator navigation link missing');
studio_assert(is_file($rendererPath), 'independent HTML studio renderer missing');
studio_assert(is_file($cssPath), 'independent HTML studio stylesheet missing');
studio_assert(is_file($jsPath), 'independent HTML studio script missing');

$renderer = is_file($rendererPath) ? (string) file_get_contents($rendererPath) : '';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$js = is_file($jsPath) ? (string) file_get_contents($jsPath) : '';
studio_assert(str_contains($renderer, 'portal_render_html_studio') && str_contains($renderer, 'portal_is_admin'), 'renderer must fail closed for non-admin user');
studio_assert(str_contains($renderer, 'sandbox="allow-same-origin"'), 'preview iframes must omit allow-scripts and forms');
foreach (['data-studio-mode="source"','data-studio-mode="compare"','data-studio-mode="preview"','data-studio-mode="annotate"','data-studio-mode="inspect"','data-studio-mode="code"','id="studio-source-canvas"','data-studio-canvas-selection','id="studio-selection-delete"','id="studio-source-compare"','id="studio-html-compare"','id="studio-download-html"'] as $token) {
    studio_assert(str_contains($renderer, $token), 'renderer contract missing: ' . $token);
}
foreach (['data-draw-tool="select"','data-draw-tool="pen"','data-draw-tool="rect"','data-draw-tool="text"','studio-image-upload','studio-html-upload','accept=".html,.htm,text/html"','studio-draw-undo','studio-draw-clear','studio-generate-html'] as $token) {
    studio_assert(str_contains($renderer, $token), 'drawing/import tool missing: ' . $token);
}
studio_assert(str_contains($renderer, 'data-studio-requires-comparison-source'), 'comparison must remain separately gated for HTML-file starts');
studio_assert(str_contains($js, 'DOMParser') && str_contains($js, 'adoptedStyleSheets'), 'preview must sanitize HTML and apply CSS without weakening CSP');
studio_assert(str_contains($js, 'function sanitizeCss') && str_contains($js, "default-src 'none'") && str_contains($js, 'form-action'), 'preview and exported HTML must fail closed against authenticated resource requests');
studio_assert(str_contains($js, "name === 'srcset'") && str_contains($js, "name === 'ping'") && str_contains($js, 'data:image\\/'), 'HTML resource URL allowlist is incomplete');
studio_assert(str_contains($js, '@import') && str_contains($js, 'url\\s*\\('), 'CSS network-resource rejection is missing');
studio_assert(str_contains($js, 'function updateCanvasSelection') && str_contains($js, "selectionAction = 'move'") && str_contains($js, 'deleteCanvasSelection'), 'select tool must select, move, and delete canvas pixels');
studio_assert(str_contains($js, "toDataURL('image/webp'") && str_contains($js, 'sourceImage') && str_contains($js, 'restoreSourceCanvas') && str_contains($js, 'annotations:'), 'browser draft must persist source canvas and annotation state');
studio_assert(substr_count($renderer, 'data-studio-requires-generated') >= 5 && str_contains($renderer, 'data-studio-generation-status'), 'downstream HTML modes must start locked until explicit generation');
studio_assert(str_contains($js, 'let htmlGenerated') && str_contains($js, 'function updateGeneratedAvailability') && str_contains($js, "if (!htmlGenerated"), 'source-to-generated workflow gate is missing');
foreach (['data-annotation-tool="rect"','data-annotation-tool="circle"','data-annotation-tool="arrow"','data-annotation-tool="number"','data-studio-annotation-list'] as $token) {
    studio_assert(str_contains($renderer, $token), 'numbered multi-tool annotation contract missing: ' . $token);
}
studio_assert(!str_contains($renderer, 'data-studio-annotation-text'), 'legacy single annotation textarea must be removed');
studio_assert(str_contains($js, 'let annotationRecords') && str_contains($js, 'function createAnnotationRecord') && str_contains($js, 'function renderAnnotationList') && str_contains($js, 'annotationId'), 'annotation identity/list synchronization is missing');
studio_assert(str_contains($css, '.studio-annotation-arrow') && str_contains($css, '.studio-annotation-circle') && str_contains($css, '.studio-annotation-number'), 'annotation shape styles are missing');
foreach (['data-studio-generate-instructions','data-studio-instruction-output','data-studio-copy-instructions','data-studio-download-instructions','data-studio-download-json'] as $token) {
    studio_assert(str_contains($renderer, $token), 'AI modification instruction action missing: ' . $token);
}
studio_assert(str_contains($js, 'function buildInstructionPayload') && str_contains($js, 'function buildInstructionText') && str_contains($js, 'function invalidateInstructions') && str_contains($js, 'function downloadStudioFile'), 'instruction generation/export implementation missing');
studio_assert(str_contains($js, 'application/json;charset=utf-8') && str_contains($js, 'text/plain;charset=utf-8'), 'instruction downloads must use explicit UTF-8 MIME types');
studio_assert(!str_contains($js, 'eval(') && !str_contains($js, 'new Function('), 'dynamic script execution is forbidden');
studio_assert(str_contains($js, 'localStorage') && str_contains($js, 'text/html;charset=utf-8'), 'local draft and HTML download paths missing');
studio_assert(str_contains($js, "entryKind: studioEntryKind") && str_contains($js, "studioEntryKind = 'html'") && str_contains($js, 'extractImportedHtml'), 'HTML-file initial-content state and parser are missing');
studio_assert(str_contains($js, 'entryOperationGeneration') && str_contains($js, 'operationGeneration !== entryOperationGeneration') && str_contains($js, 'cancelPendingEntryOperation'), 'overlapping HTML/image imports and user edits must be newest-operation-wins');
studio_assert(str_contains($css, '.html-studio-page') && str_contains($css, '@media'), 'scoped responsive studio CSS missing');

echo "[OK] Independent administrator-only HTML studio contracts passed.\n";
