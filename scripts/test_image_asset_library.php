<?php
declare(strict_types=1);

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$bootstrap = file_get_contents($root . '/app/bootstrap.php');
$staging = file_get_contents($root . '/app/staging.php');
$bridge = file_get_contents($root . '/scripts/document_bridge.ps1');
$imageModule = is_file($root . '/app/image_library.php') ? file_get_contents($root . '/app/image_library.php') : '';
$imageJs = is_file($root . '/assets/image-library.js') ? file_get_contents($root . '/assets/image-library.js') : '';
$imageGeometryJs = is_file($root . '/assets/image-annotation-geometry.js') ? file_get_contents($root . '/assets/image-annotation-geometry.js') : '';
$appCss = is_file($root . '/assets/app.css') ? file_get_contents($root . '/assets/app.css') : '';
$migration = is_file($root . '/database/014_image_asset_library.sql') ? file_get_contents($root . '/database/014_image_asset_library.sql') : '';
$toolbarMigration = is_file($root . '/database/015_image_annotation_tools.sql') ? file_get_contents($root . '/database/015_image_annotation_tools.sql') : '';
$exporter = is_file($root . '/scripts/image_exporter.py') ? file_get_contents($root . '/scripts/image_exporter.py') : '';

assert_true($index !== false && str_contains($index, "'image_review'"), 'image_review route must be allowlisted.');
assert_true(str_contains($index, "'visual_assets'"), 'visual_assets route must be allowlisted.');
assert_true(str_contains($index, "'image_asset'"), 'image_asset route must be allowlisted.');
assert_true(str_contains($index, "'image_annotations_save'"), 'annotation save route must be allowlisted.');
assert_true(str_contains($index, "'image_annotation_status'"), 'annotation status route must be allowlisted.');
assert_true(str_contains($index, "'image_export'"), 'multi-image export route must be allowlisted.');
assert_true(str_contains($index, "if (\$action === 'visual_assets')") && str_contains($index, 'portal_render_image_library_page'), 'visual assets must have an independent page dispatcher.');
assert_true(!str_contains($index, "\$content .= portal_render_image_library_panel(\$database, \$user);"), 'documents page must not embed the visual asset library.');
assert_true(str_contains($index, "\$filters['exclude_visual_assets'] = true") && str_contains($bootstrap, "d.extension NOT IN ('png','jpg','jpeg','webp','gif')"), 'general document results must exclude visual assets.');
assert_true(str_contains($bootstrap, "portal_url('visual_assets')") && str_contains($bootstrap, 'AI 圖像／資訊圖表'), 'main navigation must expose the independent visual asset library.');
assert_true(str_contains($imageModule, 'function portal_render_image_library_page'), 'visual asset module must render a standalone page.');
assert_true(str_contains($index, 'portal_render_image_library_page($database, $config, $user)'), 'visual asset dispatcher must pass the document-root config used to validate card targets.');
assert_true(str_contains($imageModule, 'portal_list_image_documents(PDO $database, array $config, array $user, string $sort')
    && str_contains($imageModule, 'portal_document_file_path($config, $document)')
    && str_contains($imageModule, 'catch (RuntimeException'), 'visual asset cards must omit stale catalog rows whose private source file cannot be opened.');
$cardStart = strpos($imageModule, '$cards .=');
$cardEnd = strpos($imageModule, "if (\$cards === '')", $cardStart === false ? 0 : $cardStart);
$cardSource = ($cardStart !== false && $cardEnd !== false) ? substr($imageModule, $cardStart, $cardEnd - $cardStart) : '';
assert_true(strpos($cardSource, 'image-card-preview') < strpos($cardSource, 'class="image-select"'), 'per-card selection control must render below the image instead of overlaying it.');
assert_true(str_contains($imageModule, 'image-export-summary') && str_contains($imageModule, 'image-export-options') && str_contains($imageModule, 'image-card-actions'), 'visual asset controls must use separated, readable toolbar and card action groups.');
assert_true(str_contains($appCss, '.image-export-summary') && str_contains($appCss, '.image-export-options')
    && str_contains($appCss, '.image-card-actions') && str_contains($appCss, 'align-content: start'), 'visual asset CSS must keep controls spacious and cards compact without bottom whitespace.');
assert_true(str_contains($imageModule, 'function portal_image_sort')
    && str_contains($imageModule, "'created_desc'") && str_contains($imageModule, "'created_asc'")
    && str_contains($imageModule, "'modified_desc'") && str_contains($imageModule, "'modified_asc'")
    && str_contains($imageModule, "'name_asc'") && str_contains($imageModule, "'name_desc'")
    && str_contains($imageModule, 'data-library-sort-menu') && str_contains($imageModule, 'library-sort-menu-panel'), 'visual asset heading must expose the same six validated sort choices as the document library.');
assert_true(str_contains($imageModule, 'd.created_at DESC, d.document_id DESC')
    && str_contains($imageModule, 'd.source_modified_at ASC, d.document_id ASC')
    && str_contains($imageModule, 'd.title ASC, d.file_name ASC, d.document_id ASC'), 'visual asset query must map validated sort keys to fixed SQL ORDER BY clauses.');
assert_true(str_contains($imageModule, 'image-export-options panel')
    && str_contains($imageModule, 'image-export-field-name')
    && str_contains($imageModule, 'image-export-checkbox-control')
    && str_contains($appCss, '.image-export-options.panel')
    && str_contains($appCss, '.image-export-field-name'), 'export format, GIF duration, and annotation option must share one panel with aligned field-name rows.');

assert_true(str_contains($bootstrap, 'PORTAL_DOCUMENT_HASH_MAX_BYTES = 524288000'), 'catalog hashing must cover the full 500 MiB upload limit.');
foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $extension) {
    assert_true(str_contains($bootstrap, "'{$extension}'"), "catalog must allow {$extension}.");
    assert_true(str_contains($staging, "'{$extension}'"), "staging must allow {$extension}.");
    assert_true(str_contains($bridge, "'.{$extension}'"), "bridge must allow {$extension}.");
}
assert_true(str_contains($staging, 'getimagesize'), 'staging must validate image bytes, not only file extensions.');
assert_true(str_contains($staging, 'image/'), 'staging preview must stream image MIME types privately.');
assert_true(str_contains($staging, 'portal_staging_validate_decodable_image') && str_contains($exporter, "'--validate'"), 'staged images must pass bounded full-decoder validation.');

assert_true(str_contains($migration, 'dbo.image_annotations'), 'migration must create image annotations.');
assert_true(str_contains($migration, 'source_content_hash'), 'annotations must be pinned to an immutable source hash.');
assert_true(str_contains($migration, 'x_norm') && str_contains($migration, 'width_norm'), 'annotations must store normalized rectangles.');
assert_true(str_contains($migration, "('visual_asset'"), 'migration must seed a visual asset document type.');
assert_true(str_contains($toolbarMigration, 'annotation_type_code') && str_contains($toolbarMigration, 'geometry_json') && str_contains($toolbarMigration, 'style_json'), 'toolbar migration must persist typed geometry and style.');

assert_true(str_contains($imageModule, 'portal_handle_image_asset'), 'authenticated private image streaming is required.');
assert_true(str_contains($imageModule, 'expected_hash') && str_contains($imageModule, 'IMAGE_ASSET_CONFLICT'), 'private image streaming must be bound to the expected catalog hash.');
assert_true(str_contains($imageModule, 'hash_update_stream') && str_contains($imageModule, 'fpassthru'), 'asset streaming must hash and stream the same open handle.');
assert_true(substr_count($imageModule, "Cache-Control: private, no-store") >= 2, 'private source images and generated exports must both disable caching.');
assert_true(str_contains($imageModule, 'portal_handle_image_annotations_save'), 'annotation persistence handler is required.');
assert_true(str_contains($imageModule, 'SET x_norm = ?'), 'saving annotations must preserve existing annotation identity and creation time.');
assert_true(str_contains($imageModule, 'portal_handle_image_annotation_status'), 'annotation status handler is required.');
assert_true(str_contains($imageModule, 'status_code'), 'annotation processing status must be loaded and persisted.');
assert_true(str_contains($imageModule, 'portal_handle_image_export'), 'multi-image export handler is required.');
assert_true(str_contains($imageModule, 'portal_assert_csrf'), 'mutations must enforce CSRF.');
assert_true(str_contains($imageModule, 'portal_can_edit_documents'), 'annotation mutation must enforce editor/admin permission.');
assert_true(str_contains($imageModule, 'hash_file'), 'annotation and export must re-check source hashes.');
assert_true(str_contains($imageModule, "['source_hash']") || str_contains($imageModule, "['source_hash'"), 'annotation saves must submit and verify the page-observed source hash.');
assert_true(str_contains($imageModule, 'immutable_source') && str_contains($imageModule, 'copy('), 'exports must use a verified immutable private snapshot.');
assert_true(str_contains($imageModule, 'portal_staging_root($config)'), 'exports must resolve their workspace through the hardened staging root helper.');
assert_true(str_contains($imageModule, 'flock(') && str_contains($imageModule, 'LOCK_NB'), 'exports must enforce nonblocking concurrency locks.');
assert_true(strpos($imageModule, 'portal_image_acquire_export_locks($root') < strpos($imageModule, '$totalSourceBytes=0'), 'export locks must be acquired before source hashing and annotation loading.');
assert_true(str_contains($imageModule, 'portal_validate_image_export_output'), 'generated exports must be structurally validated before download.');
assert_true(str_contains($imageModule, 'proc_open'), 'export must use an argument-array process invocation.');
assert_true(str_contains($imageModule, 'proc_terminate') && str_contains($imageModule, 'microtime(true)'), 'export worker must have a bounded timeout.');
assert_true(!str_contains($imageModule, 'shell_exec('), 'export must not use shell_exec.');
assert_true(str_contains($imageModule, 'PORTAL_IMAGE_EXPORT_MAX_ITEMS'), 'export must enforce a bounded item count.');
assert_true(str_contains($imageModule, 'PORTAL_IMAGE_EXPORT_MAX_SOURCE_BYTES') && str_contains($imageModule, 'PORTAL_IMAGE_EXPORT_MAX_OUTPUT_BYTES'), 'export must bound aggregate input and output bytes.');
assert_true(str_contains($imageModule, 'PORTAL_IMAGE_EXPORT_MAX_ANNOTATIONS'), 'export must bound aggregate annotation work.');
assert_true(str_contains($imageModule, "['rectangle','arrow','highlight','text','number']") && str_contains($imageModule, 'portal_image_clean_geometry'), 'backend must validate all image annotation tools.');

assert_true(str_contains($imageJs, "mouse:down") && str_contains($imageJs, "mouse:up") && str_contains($imageJs, 'object:modified'), 'browser annotation drawing and geometry editing are required.');
assert_true(str_contains($imageJs, 'fabricCanvas.getWidth') && str_contains($imageJs, 'fabricCanvas.getHeight'), 'selection coordinates must be normalized against the rendered image canvas.');
assert_true(str_contains($imageJs, "originX:'left'") && str_contains($imageJs, "originY:'top'") && str_contains($imageJs, 'getScenePoint'), 'Fabric geometry must use explicit top-left origins and one scene-coordinate API.');
assert_true(str_contains($imageJs, 'lockScalingFlip:true'), 'interactive Fabric controls must not flip annotation geometry through zero scale.');
assert_true(str_contains($bootstrap, 'assets/image-annotation-geometry.js') && str_contains($imageJs, 'TWImageGeometry') && str_contains($imageGeometryJs, 'annotationFromOuter'), 'tested coordinate helpers must load before the image editor.');
foreach (['select','rectangle','arrow','highlight','text','number'] as $tool) assert_true(str_contains($imageJs, "'{$tool}'"), "image editor must support {$tool} tool.");
assert_true(str_contains($imageJs, 'undoStack') && str_contains($imageJs, 'redoStack') && str_contains($imageJs, 'delete-selection'), 'image editor must support undo, redo, and delete selection.');
assert_true(str_contains($imageJs, 'item.publicId&&(canManageAll||ownedOpen(item))'), 'unsaved annotations must not expose a server status action.');
assert_true(str_contains($imageJs, 'zoom-in') && str_contains($imageJs, 'zoom-out') && str_contains($imageJs, 'zoom-reset'), 'image editor must support zoom and fit width.');
assert_true(str_contains($imageJs, 'data-image-move-up') && str_contains($imageJs, 'insertBefore'), 'selected images must support explicit export ordering.');
assert_true(!str_contains($imageJs, '.innerHTML ='), 'image editor must not insert untrusted text with innerHTML.');
assert_true(str_contains($appCss, '.image-review-page .page-shell') && str_contains($appCss, 'width: 100%'), 'image review page must use a full-width shell.');
assert_true(str_contains($appCss, '@media (max-width: 900px)') && str_contains($appCss, '.image-review-workspace'), 'image review workspace must define responsive stacking behavior.');
assert_true(str_contains($appCss, 'body.image-review-page .site-nav { overflow-x: auto;') && str_contains($appCss, 'body.image-review-page .site-nav a { flex: 0 0 auto;'), 'mobile navigation must scroll internally instead of widening the image review page.');
assert_true(str_contains($appCss, '.image-review-header > div { min-width: 0;') && str_contains($appCss, '.image-review-header p { overflow-wrap: anywhere;'), 'mobile image review descriptions must wrap inside the viewport.');
assert_true(str_contains($exporter, "'gif'") && str_contains($exporter, "'pdf'") && str_contains($exporter, "'pptx'"), 'exporter must support GIF, PDF, and PPTX.');
assert_true(str_contains($exporter, 'ZipFile'), 'PPTX export must create a real OOXML package.');
assert_true(str_contains($exporter, 'MAX_TOTAL_SOURCE_PIXELS'), 'exporter must bound aggregate decoded image work.');
assert_true(str_contains($exporter, 'source hash mismatch') && str_contains($exporter, 'expected_hash'), 'worker must revalidate each immutable source snapshot hash.');
assert_true(str_contains($exporter, 'validate_output') && str_contains($exporter, 'startxref'), 'worker must fully validate generated output structure and item counts.');
assert_true(str_contains($exporter, "annotation_type") && str_contains($exporter, "'arrow'") && str_contains($exporter, "'highlight'") && str_contains($exporter, "'number'"), 'exports must render typed annotations.');

fwrite(STDOUT, "[OK] image asset library contracts passed.\n");
