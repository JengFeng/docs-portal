<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function admin_catalog_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$filters = portal_admin_catalog_filters([
    'file_q' => '  供水監測  ',
    'type' => 'Design_Doc',
    'extension' => 'PPTX',
    'modified' => '30d',
    'sort' => 'filename_desc',
]);
admin_catalog_test_assert($filters['filename_search'] === '供水監測', 'Filename search was not normalized.');
admin_catalog_test_assert($filters['type'] === 'design_doc', 'Document type filter was not normalized.');
admin_catalog_test_assert($filters['extension'] === 'pptx', 'Extension filter was not normalized.');
admin_catalog_test_assert($filters['modified'] === '30d', 'Modified-time filter was not accepted.');
admin_catalog_test_assert($filters['sort'] === 'filename_desc', 'Sort option was not accepted.');

$invalid = portal_admin_catalog_filters([
    'extension' => 'exe',
    'modified' => 'forever',
    'sort' => 'DROP TABLE documents',
]);
admin_catalog_test_assert($invalid['extension'] === '', 'Invalid extension must fail closed.');
admin_catalog_test_assert($invalid['modified'] === '', 'Invalid time filter must fail closed.');
admin_catalog_test_assert($invalid['sort'] === 'modified_desc', 'Invalid sort must use the safe default.');
admin_catalog_test_assert(
    portal_escape_like_pattern('100%_供水~監測') === '100~%~_供水~~監測',
    'Filename LIKE wildcards must be escaped for literal search.'
);
admin_catalog_test_assert(portal_admin_batch_mode('replace') === 'replace', 'Replace batch mode must be accepted.');
admin_catalog_test_assert(portal_admin_batch_mode('add') === 'add', 'Add batch mode must be accepted.');
admin_catalog_test_assert(portal_admin_batch_mode('remove') === 'remove', 'Remove batch mode must be accepted.');
admin_catalog_test_assert(portal_admin_batch_mode('unchanged') === 'unchanged', 'Unchanged batch mode must be accepted.');
$invalidModeRejected = false;
try {
    portal_admin_batch_mode('invalid');
} catch (RuntimeException) {
    $invalidModeRejected = true;
}
admin_catalog_test_assert($invalidModeRejected, 'Invalid batch mode must be rejected explicitly.');
admin_catalog_test_assert(
    portal_apply_batch_set(['requirements', 'spec'], ['design'], 'unchanged') === ['requirements', 'spec'],
    'Unchanged mode must preserve the current set.'
);
admin_catalog_test_assert(
    portal_apply_batch_set(['requirements', 'spec'], ['design', 'spec'], 'replace') === ['design', 'spec'],
    'Replace mode must completely replace the current set.'
);
admin_catalog_test_assert(
    portal_apply_batch_set(['requirements', 'spec'], ['design', 'spec'], 'add') === ['requirements', 'spec', 'design'],
    'Add mode must preserve current values and add selected values.'
);
admin_catalog_test_assert(
    portal_apply_batch_set(['requirements', 'spec'], ['spec'], 'remove') === ['requirements'],
    'Remove mode must remove only selected values.'
);

$selection = portal_admin_batch_selection([
    'document_ids' => [
        '11111111-1111-4111-8111-111111111111',
        '11111111-1111-4111-8111-111111111111',
        'not-a-uuid',
        '22222222-2222-4222-8222-222222222222',
    ],
]);
admin_catalog_test_assert($selection === [
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
], 'Batch selection must validate and deduplicate document IDs.');

$largeSelectionInput = [];
for ($index = 0; $index < 101; $index++) {
    $largeSelectionInput[] = sprintf('00000000-0000-4000-8000-%012x', $index);
}
admin_catalog_test_assert(
    count(portal_admin_batch_selection(['document_ids' => $largeSelectionInput])) === 101,
    'Batch selection must not silently truncate valid document IDs below the catalog limit.'
);

$overLimitInput = [];
for ($index = 0; $index <= PORTAL_DOCUMENT_LIST_LIMIT; $index++) {
    $overLimitInput[] = sprintf('10000000-0000-4000-8000-%012x', $index);
}
$overLimitRejected = false;
try {
    portal_admin_batch_selection(['document_ids' => $overLimitInput]);
} catch (RuntimeException $exception) {
    $overLimitRejected = str_contains($exception->getMessage(), (string) PORTAL_DOCUMENT_LIST_LIMIT);
}
admin_catalog_test_assert($overLimitRejected, 'Batch selection above the catalog limit must be rejected explicitly.');

$indexSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
foreach ([
    "'admin_documents_bulk_update'",
    'portal_handle_admin_documents_bulk_update',
    'name="file_q"',
    'name="extension"',
    'name="modified"',
    'name="sort"',
    'name="document_ids[]"',
    'name="type_mode"',
    'name="phase_mode"',
    '取代：完全改成所選種類',
    '新增：保留原種類，再加入所選種類',
    '移除：只移除所選種類',
    '最近編輯文件',
    'portal_list_recent_admin_documents',
    "\$filters['include_archived'] = true;",
] as $needle) {
    admin_catalog_test_assert(str_contains($indexSource, $needle), 'Admin catalog contract missing: ' . $needle);
}

$bootstrapSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php');
foreach ([
    'function portal_bulk_update_document_metadata',
    'function portal_admin_batch_mode',
    'function portal_apply_batch_set',
    'source_modified_at >= DATEADD',
    'd.extension = ?',
    "'filename_desc' => 'd.file_name DESC",
    'document_metadata_bulk_update',
    'type_mode=',
    'phase_mode=',
    'SET document_type_id = (',
    '$syncPrimaryType->execute([$documentId, $documentId]);',
    'AS active_type_count',
    'dt.is_active = 1',
    'AS active_role_count',
    'p.is_active = 1',
    'ORDER BY d.updated_at DESC',
] as $needle) {
    admin_catalog_test_assert(str_contains($bootstrapSource, $needle), 'Backend contract missing: ' . $needle);
}

$cssSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.css');
foreach (['.admin-catalog-filter', '.batch-editor', '.recent-document-list'] as $needle) {
    admin_catalog_test_assert(str_contains($cssSource, $needle), 'Responsive admin catalog style missing: ' . $needle);
}
admin_catalog_test_assert(
    preg_match('/\.batch-controls\s*\{[^}]*align-items:\s*start;/s', $cssSource) === 1,
    'Expanded batch details must not push sibling controls to the bottom of the grid row.'
);
admin_catalog_test_assert(
    str_contains($cssSource, '.batch-controls > details, .batch-controls > .primary-button'),
    'Desktop batch control tops must remain aligned when a details panel expands.'
);
admin_catalog_test_assert(
    preg_match('/\.batch-option-fieldset:disabled\s*\{[^}]*display:\s*none;/s', $cssSource) === 1,
    'Batch option details must stay hidden until an explicit mode is selected.'
);

$jsSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'app.js');
admin_catalog_test_assert(str_contains($jsSource, '[data-select-all-documents]'), 'Select-all batch interaction is missing.');
admin_catalog_test_assert(str_contains($jsSource, '[data-batch-mode]'), 'Batch mode interaction is missing.');
admin_catalog_test_assert(str_contains($jsSource, '請先勾選至少一個要套用的'), 'Batch mode selection needs immediate client-side guidance.');

echo "[OK] Admin catalog filter, sort, batch edit, and recent-edit contracts passed.\n";
