<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$allowed = [
    'created_desc', 'created_asc',
    'modified_desc', 'modified_asc',
    'name_asc', 'name_desc',
];
foreach ($allowed as $sort) {
    $filters = portal_catalog_filters(['sort' => $sort]);
    assert_true(($filters['sort'] ?? null) === $sort, "document library sort {$sort} must be allowlisted");
}
assert_true(portal_catalog_filters(['sort' => 'drop_table'])['sort'] === 'modified_desc', 'invalid document-library sort must fail closed to modified_desc');
$roundTrip = portal_catalog_filters([
    'view' => 'type', 'type' => 'design', 'stage_state' => '04', 'role_state' => 'output', 'sort' => 'name_desc',
]);
assert_true(($roundTrip['stage_state'] ?? '') === '04' && ($roundTrip['role_state'] ?? '') === 'output' && ($roundTrip['type'] ?? '') === 'design', 'view-specific filters must survive a type/stage round trip');
$invalidState = portal_catalog_filters(['stage_state' => 'x', 'role_state' => 'owner', 'type_state' => 'DROP TABLE']);
assert_true(($invalidState['stage_state'] ?? null) === '' && ($invalidState['role_state'] ?? null) === 'all' && ($invalidState['type_state'] ?? null) === '', 'saved filter state must use the same fail-closed validation');
$canonicalStage = portal_catalog_filters(['view' => 'stage', 'stage' => '04', 'role' => 'output', 'type' => 'design']);
assert_true($canonicalStage['stage'] === '04' && $canonicalStage['role'] === 'output' && $canonicalStage['type'] === '' && $canonicalStage['type_state'] === 'design', 'stage view must move an inactive type filter into saved state instead of applying it');
$canonicalType = portal_catalog_filters(['view' => 'type', 'type' => 'design', 'stage' => '04', 'role' => 'output']);
assert_true($canonicalType['type'] === 'design' && $canonicalType['stage'] === '' && $canonicalType['role'] === 'all' && $canonicalType['stage_state'] === '04' && $canonicalType['role_state'] === 'output', 'type view must move inactive stage and role filters into saved state instead of applying them');

$index = file_get_contents(dirname(__DIR__) . '/index.php');
$bootstrap = file_get_contents(dirname(__DIR__) . '/app/bootstrap.php');
$css = file_get_contents(dirname(__DIR__) . '/assets/app.css');
$js = file_get_contents(dirname(__DIR__) . '/assets/app.js');
assert_true(is_string($index) && is_string($bootstrap) && is_string($css) && is_string($js), 'document library source files must be readable');

foreach (['建立時間：新到舊', '建立時間：舊到新', '修改時間：新到舊', '修改時間：舊到新', '名稱：昇冪', '名稱：降冪'] as $label) {
    assert_true(str_contains($index, $label), "sort control must include {$label}");
}
assert_true(str_contains($index,"['action' => 'documents', 'view' => \$filters['view'], 'sort' => \$filters['sort']]")&&str_contains($index,'portal_render_hidden_values($hidden)'), 'filter form must preserve the active sort through the shared hidden-value renderer');
assert_true(!str_contains($index, 'class="library-sort-field"'), 'sort select must no longer be permanently displayed inside the filter form');
assert_true(str_contains($index, 'data-library-sort-menu') && str_contains($index, 'portal_render_library_sort_menu($filters)'), 'document results heading must render a compact sort menu beside the count');
assert_true(str_contains($index, 'aria-label="文件排序"') && str_contains($index, 'library-sort-current'), 'sort menu trigger must be recognizable and expose the current sort accessibly');
assert_true(str_contains($js, "form.matches('[data-library-sort-menu]')") && str_contains($js, 'form.requestSubmit()'), 'changing the sort menu must close and submit immediately without an extra confirmation');
assert_true(str_contains($css, '.library-result-actions') && str_contains($css, '.library-sort-menu-panel'), 'result heading sort menu must have compact responsive positioning styles');
assert_true(str_contains($index, "'sort' => \$filters['sort']"), 'view and stage links must preserve the selected sort');
foreach (['stage_state', 'role_state', 'type_state'] as $stateKey) {
    assert_true(str_contains($index, $stateKey), "view links and forms must preserve {$stateKey}");
}
assert_true(str_contains($index, "'q' => \$filters['search']") && str_contains($index, "'role' => \$filters['roles']"), 'stage-card navigation must preserve active search and all selected role filters');
assert_true(str_contains($bootstrap, 'd.relative_path') && str_contains($bootstrap, 'd.created_at') && str_contains($bootstrap, 'd.updated_at'), 'document list query must select path and lifecycle timestamps');
foreach (['created_desc', 'created_asc', 'name_asc', 'name_desc'] as $sort) {
    assert_true(str_contains($bootstrap, "'{$sort}' =>"), "query ORDER BY allowlist must implement {$sort}");
}
assert_true(str_contains($index, 'document-row-path') && str_contains($index, "\$document['relative_path']"), 'each document row must render escaped relative path information');
assert_true(str_contains($index, "\$filters['exclude_internal_markdown'] = true;"), 'general document library must explicitly hide internal Markdown files');
assert_true(str_contains($bootstrap, "d.extension <> 'md'"), 'catalog query must support internal Markdown exclusion');
assert_true(str_contains($index, "portal_render_page('文件庫', \$content, \$user, false, 'document-library-page')"), 'document library must use a scoped page class');
assert_true(str_contains($css, 'body.document-library-page .page-shell') && str_contains($css, 'document-library-page .document-row-title'), 'document library must have scoped wide/readable styles');
assert_true(str_contains($css, 'document-library-page .document-row-path'), 'relative path must have readable wrapping styles');
assert_true(str_contains($css, '@media (max-width: 1100px)') && str_contains($css, 'document-library-page .library-filter'), 'sorting controls must have tablet RWD rules');
assert_true(str_contains($css, 'body.document-library-page .site-nav') && str_contains($css, 'white-space: nowrap'), 'mobile document-library navigation must scroll internally without wrapping labels');
assert_true(str_contains($css, '.document-library-page .library-filter { grid-template-columns: 1fr; }'), '390px document-library filters must stack in one column');

echo "[OK] document library readability, sorting, path, and RWD contracts passed.\n";
