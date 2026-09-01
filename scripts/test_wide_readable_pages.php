<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$imageLibrary = file_get_contents($root . '/app/image_library.php');
$staging = file_get_contents($root . '/app/staging.php');
$css = file_get_contents($root . '/assets/app.css');
assert_true($index !== false && $imageLibrary !== false && $staging !== false && $css !== false, 'wide readable page source files must be readable');

assert_true(str_contains($index, "'wide-readable-page admin-dashboard-page'"), 'admin dashboard must use the wide readable body scope');
assert_true(str_contains($imageLibrary, "'wide-readable-page visual-assets-page'"), 'visual asset library must use the wide readable body scope');
assert_true(str_contains($staging, "'wide-readable-page staging-workspace-page'"), 'staging workspace must use the wide readable body scope');

assert_true(str_contains($css, 'body.wide-readable-page { font-size: 1rem; }'), 'all three pages must inherit the selected site-wide small, medium, or large root size');
assert_true(str_contains($css, 'body.wide-readable-page .page-shell') && str_contains($css, '1760px'), 'all three pages must use the same near-full desktop width as the document library');
assert_true(str_contains($css, '.wide-readable-page .primary-button') && str_contains($css, '.wide-readable-page input:not([type="checkbox"])'), 'buttons and controls must receive readable sizing');
assert_true(str_contains($css, '.admin-dashboard-page .admin-table') && str_contains($css, '.admin-dashboard-page .batch-controls'), 'admin table and bulk controls must receive scoped readable sizing');
assert_true(substr_count($css, '.admin-dashboard-page .admin-catalog-filter') >= 4, 'admin filter grid must have scoped desktop, tablet, and mobile column rules');
assert_true(str_contains($css, '.staging-workspace-page .staging-upload-panel label') && str_contains($css, '.staging-workspace-page .recent-document-list'), 'staging controls and file rows must receive scoped readable sizing');
assert_true(str_contains($css, '.visual-assets-page .image-card-body') && str_contains($css, '.visual-assets-page .image-export-toolbar label'), 'visual asset cards and export controls must receive scoped readable sizing');
assert_true(str_contains($css, 'body.wide-readable-page .site-nav') && str_contains($css, 'white-space: nowrap'), 'mobile navigation must scroll internally without wrapping labels');
assert_true(str_contains($css, '@media (max-width: 600px)') && str_contains($css, 'body.wide-readable-page .page-shell'), 'wide readable pages must retain safe mobile gutters');

echo "[OK] visual assets, staging workspace, and admin dashboard wide-readable contracts passed.\n";
