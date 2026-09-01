<?php
declare(strict_types=1);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function preview_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function preview_remove_tree(string $path): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'twwater-preview-formats-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
$fixtures = [
    'pptx' => 'C:/Users/gisadmin/我的雲端硬碟/115供水監測/供水監測文件即時瀏覽平台建議方案.pptx',
    'pdf' => 'C:/Users/gisadmin/我的雲端硬碟/115供水監測/會議記錄/1150709遠端連線管理會議/0709會議紀錄_遠端連線管理系統環境需求討論會議(初稿).pdf',
    'docx' => 'C:/Users/gisadmin/我的雲端硬碟/115供水監測/會議記錄/1150709遠端連線管理會議/0709會議紀錄_遠端連線管理系統環境需求討論會議(初稿).docx',
    'xlsx' => 'C:/Users/gisadmin/我的雲端硬碟/115供水監測/台水遠端連線管理系統主機資訊.xlsx',
];
$libreOffice = 'C:/Program Files/LibreOffice/program/soffice.com';
try {
    foreach ($fixtures as $extension => $fixture) {
        preview_assert(is_file($fixture), "Fixture missing: {$extension}");
        $workspace = $root . DIRECTORY_SEPARATOR . $extension;
        mkdir($workspace, 0770, true);
        $source = $workspace . DIRECTORY_SEPARATOR . 'source.' . $extension;
        copy($fixture, $source);
        $preview = portal_staging_build_preview($source, $extension, $workspace, $libreOffice);
        preview_assert(($preview['kind'] ?? '') === 'pdf', "{$extension} must produce PDF preview.");
        $pdf = $workspace . DIRECTORY_SEPARATOR . 'preview.pdf';
        preview_assert(is_file($pdf) && filesize($pdf) > 5, "{$extension} preview PDF missing.");
        preview_assert(str_starts_with((string) file_get_contents($pdf, false, null, 0, 5), '%PDF-'), "{$extension} preview PDF invalid.");
    }
    foreach (['md' => "# Heading\n\nSafe text", 'txt' => "Plain UTF-8 text\n"] as $extension => $contents) {
        $workspace = $root . DIRECTORY_SEPARATOR . $extension;
        mkdir($workspace, 0770, true);
        $source = $workspace . DIRECTORY_SEPARATOR . 'source.' . $extension;
        file_put_contents($source, $contents);
        $preview = portal_staging_build_preview($source, $extension, $workspace, $libreOffice);
        preview_assert(($preview['kind'] ?? '') === 'text' && ($preview['relative_path'] ?? '') === 'source.' . $extension, "{$extension} must produce escaped text preview.");
    }
    $largeText = $root . DIRECTORY_SEPARATOR . 'large-utf8.txt';
    file_put_contents($largeText, str_repeat("供水監測 preview line\n", 600000));
    preview_assert(filesize($largeText) > 10485760, 'Large text fixture must exceed 10 MB.');
    portal_staging_validate_artifact($largeText, 'txt');
    $boundaryText = $root . DIRECTORY_SEPARATOR . 'boundary-utf8.txt';
    file_put_contents($boundaryText, str_repeat('a', 4194303) . '供水');
    portal_staging_validate_artifact($boundaryText, 'txt');
    $invalidText = $root . DIRECTORY_SEPARATOR . 'invalid-utf8.txt';
    file_put_contents($invalidText, "broken\xE4");
    $invalidRejected = false;
    try { portal_staging_validate_artifact($invalidText, 'txt'); } catch (RuntimeException) { $invalidRejected = true; }
    preview_assert($invalidRejected, 'Incomplete UTF-8 must be rejected.');
    $bombDocx = $root . DIRECTORY_SEPARATOR . 'zip-bomb.docx';
    $zip = new ZipArchive();
    preview_assert($zip->open($bombDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'ZIP bomb fixture could not be created.');
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('word/document.xml', '<document/>');
    $zip->addFromString('word/media/bomb.bin', str_repeat("\0", 5 * 1048576));
    $zip->close();
    $bombRejected = false;
    try { portal_staging_validate_artifact($bombDocx, 'docx'); } catch (RuntimeException) { $bombRejected = true; }
    preview_assert($bombRejected, 'Highly compressed OOXML payloads must be rejected before LibreOffice.');
    echo "[OK] All six staged preview formats rendered.\n";
} finally {
    preview_remove_tree($root);
}
