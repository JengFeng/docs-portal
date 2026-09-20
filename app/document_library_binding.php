<?php
declare(strict_types=1);

/**
 * Per-site document-library authority.  The binding path is configured once
 * in IIS; the document root itself lives only in this protected JSON file.
 */
function portal_document_library_binding_config_path(): string
{
    $value = getenv('PORTAL_DOCUMENT_BINDING_CONFIG_PATH');
    return $value === false ? '' : trim((string) $value);
}

function portal_document_library_binding_read(string $path): array
{
    if ($path === '' || str_contains($path, "\0") || !is_file($path) || is_link($path) || portal_path_has_reparse_component($path)) {
        throw new RuntimeException('網站文件庫綁定設定檔無法安全讀取。');
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '' || strlen($raw) > 4096) {
        throw new RuntimeException('網站文件庫綁定設定檔格式無效。');
    }
    try {
        $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('網站文件庫綁定設定檔格式無效。');
    }
    if (!is_array($data) || array_keys($data) !== ['documentLibraryRoot'] || !is_string($data['documentLibraryRoot'])) {
        throw new RuntimeException('網站文件庫綁定設定檔格式無效。');
    }
    $root = trim($data['documentLibraryRoot']);
    if (!portal_direct_sync_root_is_allowed($root)) {
        throw new RuntimeException('網站文件庫綁定目錄無效。');
    }
    $resolved = realpath($root);
    if ($resolved === false) {
        throw new RuntimeException('網站文件庫綁定目錄無效。');
    }
    return ['document_root' => $resolved, 'config_path' => realpath($path) ?: $path];
}
