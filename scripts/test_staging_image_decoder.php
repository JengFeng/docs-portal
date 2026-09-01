<?php
declare(strict_types=1);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function image_decode_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function image_decode_remove_tree(string $path): void {
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$config = portal_config(false);
$config['preupload_preview_root'] = 'C:/TWWATER/runtime/pre-upload-previews';
$config['image_export_python'] = 'C:/TWWATER/runtime/pre-upload-previews/image-export-runtime/image_exporter.exe';
$root = portal_staging_root($config);
$workspace = $root . DIRECTORY_SEPARATOR . 'test-image-decode-' . bin2hex(random_bytes(6));
$fixture = 'C:/Users/gisadmin/我的雲端硬碟/115供水監測/infographic/third-party-login/infographic-16x9.png';
$executor = (string) ($config['image_export_python'] ?? '');
mkdir($workspace, 0700, true);
try {
    image_decode_assert(is_file($fixture), 'Real PNG fixture missing.');
    $png = $workspace . DIRECTORY_SEPARATOR . 'source.png';
    copy($fixture, $png);
    $preview = portal_staging_build_preview($png, 'png', $workspace, '', $executor);
    image_decode_assert(($preview['kind'] ?? '') === 'image', 'Valid PNG must pass full decoder validation.');
    $manifest=$workspace.DIRECTORY_SEPARATOR.'manifest.json'; $output=$workspace.DIRECTORY_SEPARATOR.'validated.gif';
    file_put_contents($manifest,json_encode(['format'=>'gif','items'=>[['path'=>$png,'expected_hash'=>hash_file('sha256',$png),'annotations'=>[]]]],JSON_THROW_ON_ERROR));
    portal_staging_run_process([$executor,$manifest,$output],dirname(__DIR__),60);
    image_decode_assert(portal_validate_image_export_output($output,'gif',1,$config), 'Complete GIF output must pass structural validation.');
    image_decode_assert(!portal_validate_image_export_output($output,'gif',2,$config), 'GIF frame count mismatch must fail structural validation.');

    $gifWorkspace = $workspace . DIRECTORY_SEPARATOR . 'bad-gif';
    mkdir($gifWorkspace, 0700, true);
    $gif = $gifWorkspace . DIRECTORY_SEPARATOR . 'source.gif';
    file_put_contents($gif, "GIF89a\x01\x00\x01\x00\x00\x00\x00");
    $rejected = false;
    try { portal_staging_build_preview($gif, 'gif', $gifWorkspace, '', $executor); }
    catch (RuntimeException) { $rejected = true; }
    image_decode_assert($rejected, 'Truncated GIF must be rejected by the full decoder.');
    $locks = portal_image_acquire_export_locks($root, 990001);
    $duplicateRejected = false;
    try { portal_image_acquire_export_locks($root, 990001); } catch (RuntimeException) { $duplicateRejected = true; }
    image_decode_assert($duplicateRejected, 'A user must not run concurrent image exports.');
    foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
    $first = portal_image_acquire_export_locks($root, 990002);
    $second = portal_image_acquire_export_locks($root, 990003);
    $globalRejected = false;
    try { portal_image_acquire_export_locks($root, 990004); } catch (RuntimeException) { $globalRejected = true; }
    image_decode_assert($globalRejected, 'The site must not run more than two image exports concurrently.');
    foreach ([$second, $first] as $held) foreach (array_reverse($held) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
    fwrite(STDOUT, "[OK] staged image full-decoder validation passed.\n");
} finally {
    image_decode_remove_tree($workspace);
}
