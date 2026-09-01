<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/index.php');
$retirement = file_get_contents($root . '/app/staging_retirement.php');
$image = file_get_contents($root . '/app/image_library.php');
$imageJs = file_get_contents($root . '/assets/image-library.js');
$presentation = file_get_contents($root . '/assets/presentation.js');
if (!is_string($source) || !is_string($retirement) || !is_string($image) || !is_string($imageJs) || !is_string($presentation)) {
    throw new RuntimeException('Phase-1 operating-copy source unreadable.');
}
foreach ([
    '直接讀取受保護的正式文件庫',
    '僅由管理者於伺服器完成放檔並重新索引',
    '網站不提供上傳',
    'Google Drive舊入口與Bridge暫時保留，待後續評估',
] as $required) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException('Missing direct-root operating copy: ' . $required);
    }
}
if (str_contains($source, '文件與圖片請統一上傳至 Google Drive')) {
    throw new RuntimeException('Legacy Drive-only upload instruction remains visible.');
}
foreach ([
    [$retirement, 'Phase 1由管理者於伺服器正式文件庫完成放檔並重新索引'],
    [$image, '資料由受保護正式文件庫讀取'],
    [$image, '網站不提供上傳'],
    [$image, '管理員確認前不得覆寫受保護正式文件來源'],
    [$imageJs, '管理員確認前不得覆寫受保護正式文件來源'],
    [$presentation, '交由管理者依Phase 1流程放入正式文件庫並重新索引'],
    [$source, '管理者完成放檔後，再由此處重新索引'],
] as [$content, $required]) {
    if (!str_contains($content, $required)) {
        throw new RuntimeException('Missing Phase-1 copy: ' . $required);
    }
}
foreach ([
    [$retirement, '統一由 Google Drive'],
    [$image, '統一上傳至 Google Drive'],
    [$image, '資料統一由 Google Drive 上傳同步'],
    [$image, '管理員確認前不得覆寫 Google Drive 同步來源'],
    [$imageJs, '管理員確認前不得覆寫 Google Drive 同步來源'],
    [$presentation, '統一上傳至 Google Drive'],
    [$source, '文件上傳且 Google Drive 同步到本機後'],
] as [$content, $forbidden]) {
    if (str_contains($content, $forbidden)) {
        throw new RuntimeException('Legacy Drive workflow remains visible: ' . $forbidden);
    }
}

echo "[OK] Direct-root Phase-1 operating copy contract passed.\n";
