<?php
declare(strict_types=1);

/**
 * Returns a bounded, metadata-only health report for one already-authorized
 * document-library root. This service neither reads file contents nor mutates
 * the filesystem.
 */
function portal_document_library_health_report(string $configuredRoot, int $maxFiles = 5000, int $maxDirectories = 2000): array
{
    $empty = [
        'status' => 'unavailable',
        'root' => '',
        'file_count' => 0,
        'directory_count' => 0,
        'empty_directory_count' => 0,
        'total_bytes' => 0,
        'extension_counts' => [],
        'exception_count' => 0,
        'exceptions' => [],
        'scanned_at' => gmdate('c'),
    ];
    if ($maxFiles < 1 || $maxDirectories < 1) {
        throw new InvalidArgumentException('Health scan limits must be positive.');
    }
    $root = realpath($configuredRoot);
    if ($root === false || !is_dir($root) || is_link($root) || !is_readable($root)) {
        return $empty;
    }

    $report = $empty;
    $report['status'] = 'healthy';
    $report['root'] = $root;
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $extensions = [];
    $emptyDirectories = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $path = $item->getPathname();
            $normalized = str_replace('\\', '/', $path);
            if (!str_starts_with($normalized, $rootPrefix)) {
                portal_document_library_health_exception($report, 'path_outside_root');
                continue;
            }
            if ($item->isLink()) {
                portal_document_library_health_exception($report, 'reparse_point_skipped');
                continue;
            }
            if ($item->isDir()) {
                $report['directory_count']++;
                if ($report['directory_count'] > $maxDirectories) {
                    portal_document_library_health_exception($report, 'directory_limit_reached');
                    break;
                }
                try {
                    $children = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
                    if (!$children->valid()) {
                        $emptyDirectories++;
                    }
                } catch (Throwable) {
                    portal_document_library_health_exception($report, 'directory_unreadable');
                }
                continue;
            }
            if (!$item->isFile()) {
                portal_document_library_health_exception($report, 'unsupported_entry_skipped');
                continue;
            }
            $report['file_count']++;
            if ($report['file_count'] > $maxFiles) {
                portal_document_library_health_exception($report, 'file_limit_reached');
                break;
            }
            try {
                $report['total_bytes'] += $item->getSize();
                $extension = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
                $extension = $extension === '' ? '(none)' : $extension;
                $extensions[$extension] = ($extensions[$extension] ?? 0) + 1;
            } catch (Throwable) {
                portal_document_library_health_exception($report, 'file_metadata_unavailable');
            }
        }
    } catch (Throwable) {
        portal_document_library_health_exception($report, 'root_scan_failed');
    }
    $report['empty_directory_count'] = $emptyDirectories;
    ksort($extensions, SORT_STRING);
    $report['extension_counts'] = $extensions;
    if ($report['exception_count'] > 0) {
        $report['status'] = 'attention';
    }
    return $report;
}

function portal_document_library_health_exception(array &$report, string $code): void
{
    $report['exception_count']++;
    if (count($report['exceptions']) < 50) {
        $report['exceptions'][] = $code;
    }
}
