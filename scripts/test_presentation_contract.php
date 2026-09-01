<?php
declare(strict_types=1);

/*
 * Pure contract tests for presentation review input validation and export.
 * No database, IIS setting, source document, preview artifact, or secret is read.
 */

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function presentation_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$annotation = portal_presentation_normalize_annotation([
    'type' => 'marker',
    'slide_number' => 2,
    'x' => 0.1,
    'y' => 0.2,
    'width' => 0.08,
    'height' => 0.08,
    'comment' => '編號 1：請調整標題',
    'style' => [
        'stroke' => '#D83B3B',
        'fill' => '#FFD84D',
        'opacity' => 0.75,
        'strokeWidth' => 3,
        'fontSize' => 22,
    ],
]);
presentation_test_assert($annotation['type'] === 'marker', 'Marker type was not preserved.');
presentation_test_assert($annotation['slide_number'] === 2, 'Slide number was not preserved.');
presentation_test_assert($annotation['style']['stroke'] === '#d83b3b', 'Style color was not normalized.');

$arrow = portal_presentation_normalize_annotation([
    'type' => 'arrow',
    'slide_number' => 1,
    'x' => 0.2,
    'y' => 0.2,
    'width' => 0.4,
    'height' => 0.3,
    'geometry' => [
        'points' => [
            ['x' => 0.2, 'y' => 0.2],
            ['x' => 0.6, 'y' => 0.5],
        ],
    ],
]);
presentation_test_assert(count($arrow['geometry']['points']) === 2, 'Arrow points were not preserved.');

$boundsRejected = false;
try {
    portal_presentation_normalize_annotation([
        'type' => 'rectangle',
        'slide_number' => 1,
        'x' => 0.9,
        'y' => 0.9,
        'width' => 0.2,
        'height' => 0.2,
    ]);
} catch (PortalPresentationHttpException $exception) {
    $boundsRejected = $exception->statusCode === 422;
}
presentation_test_assert($boundsRejected, 'Out-of-bounds annotation was not rejected.');

$pathRejected = false;
try {
    portal_presentation_safe_asset_path(
        ['presentation_preview_enabled' => true, 'presentation_preview_root' => __DIR__],
        '../preview.pdf'
    );
} catch (PortalPresentationHttpException $exception) {
    $pathRejected = $exception->statusCode === 404;
}
presentation_test_assert($pathRejected, 'Preview path traversal was not rejected.');

presentation_test_assert(
    portal_document_inline_allowed('pptx'),
    'Authenticated PPTX source must be available to the browser presentation renderer.'
);
presentation_test_assert(
    portal_document_inline_allowed('pdf'),
    'PDF must remain available for inline viewing.'
);
presentation_test_assert(
    portal_document_inline_allowed('md'),
    'Markdown source must be available to the authenticated browser review renderer.'
);
presentation_test_assert(
    portal_reviewable_document_extension('md'),
    'Markdown documents must support online review and annotations.'
);
presentation_test_assert(
    portal_reviewable_document_extension('pptx'),
    'PPTX documents must remain reviewable.'
);
presentation_test_assert(
    !portal_reviewable_document_extension('txt'),
    'Plain text must not silently inherit the Markdown annotation contract.'
);
presentation_test_assert(
    portal_review_rendition_kind(['extension' => 'md']) === 'markdown',
    'Markdown review must use its own immutable rendition kind.'
);
presentation_test_assert(
    portal_review_rendition_kind(['extension' => 'pptx']) === 'pdf',
    'PPTX review must continue to use the PDF server map.'
);
presentation_test_assert(
    function_exists('portal_ensure_markdown_review_state'),
    'Markdown review must create an immutable document version and annotation surface.'
);
$markdownMigration = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '007_markdown_review.sql';
presentation_test_assert(is_file($markdownMigration), 'Markdown review database migration is missing.');
presentation_test_assert(
    str_contains((string) file_get_contents($markdownMigration), "'markdown'"),
    'Markdown rendition kind is missing from the database contract.'
);
$indexSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
$reviewClientSource = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'presentation.js');
presentation_test_assert(
    str_contains($indexSource, "extension'] === 'md'") && str_contains($indexSource, 'Markdown 線上檢視與標注'),
    'Markdown document pages must expose the online review action.'
);
presentation_test_assert(
    str_contains($reviewClientSource, 'loadMarkdown') && str_contains($reviewClientSource, 'TWWaterMarkdown'),
    'The browser review client must dispatch Markdown sources to the Markdown renderer.'
);
presentation_test_assert(
    !portal_document_inline_allowed('docx'),
    'DOCX must remain download-only.'
);
presentation_test_assert(
    portal_document_content_disposition('pptx', true) === 'inline',
    'PPTX browser source must use inline content disposition.'
);
presentation_test_assert(
    portal_document_content_disposition('pptx', false) === 'attachment',
    'Explicit PPTX downloads must remain attachments.'
);

$queueSource = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'process_presentation_preview_queue.php');
presentation_test_assert($queueSource !== false, 'Preview queue source could not be read.');
presentation_test_assert(
    str_contains($queueSource, "status_code = 'queued' AND attempt_count < max_attempts")
    && str_contains($queueSource, "status_code = 'processing' AND attempt_count <= max_attempts")
    && str_contains($queueSource, 'lease_expires_at < SYSUTCDATETIME()'),
    'An expired final-attempt lease must be reclaimable exactly once instead of remaining stuck in processing.'
);

$markdown = portal_presentation_markdown([
    'public_id' => '00000000-0000-4000-8000-000000000001',
    'file_name' => 'briefing.pptx',
    'document_title' => '測試簡報',
    'version_number' => 3,
    'source_content_hash' => str_repeat('a', 64),
    'rendition_public_id' => '00000000-0000-4000-8000-000000000002',
    'rendition_hash' => str_repeat('b', 64),
    'status_code' => 'ready',
    'request_text' => '依標注調整。',
    'items' => [[
        'item_order' => 1,
        'slide_number' => 2,
        'annotation_type_snapshot' => 'marker',
        'geometry_json_snapshot' => '{"x":0.1,"y":0.2,"width":0.08,"height":0.08,"number":1}',
        'instruction_text' => '請調整標題。',
    ]],
]);
presentation_test_assert(str_contains($markdown, str_repeat('a', 64)), 'Export omitted the source hash.');
presentation_test_assert(str_contains($markdown, str_repeat('b', 64)), 'Export omitted the rendition hash.');
presentation_test_assert(str_contains($markdown, '第 2 頁'), 'Export omitted the slide number.');
presentation_test_assert(str_contains($markdown, 'x=0.100000'), 'Export omitted normalized geometry.');
presentation_test_assert(str_contains($markdown, '標注編號：1'), 'Export omitted the marker number.');

$markdownDocumentRequest = [
    'public_id' => '00000000-0000-4000-8000-000000000003',
    'file_name' => 'guide.md',
    'document_title' => '維護指南',
    'document_extension' => 'md',
    'version_number' => 1,
    'source_content_hash' => str_repeat('c', 64),
    'rendition_public_id' => '00000000-0000-4000-8000-000000000004',
    'rendition_hash' => str_repeat('d', 64),
    'status_code' => 'ready',
    'request_text' => '依標注更新內容。',
    'items' => [[
        'item_order' => 1,
        'slide_number' => 1,
        'annotation_type_snapshot' => 'rectangle',
        'geometry_json_snapshot' => '{"x":0.1,"y":0.2,"width":0.3,"height":0.1}',
        'instruction_text' => '請改寫此段。',
    ]],
];
$markdownDocumentExport = portal_presentation_markdown($markdownDocumentRequest);
presentation_test_assert(
    str_contains($markdownDocumentExport, '# TWWATER Markdown 文件修改需求'),
    'Markdown change request must identify the source format.'
);
presentation_test_assert(
    str_contains($markdownDocumentExport, '新版本 `.md`') && !str_contains($markdownDocumentExport, '回傳修改後 PPTX'),
    'Markdown change request must request a new .md file instead of PPTX.'
);

fwrite(STDOUT, "[OK] Presentation review contract tests passed.\n");
