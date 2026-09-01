<?php
declare(strict_types=1);

/**
 * Routes retained only for rollback/data preservation. The front controller
 * intercepts them after authentication and before every legacy handler.
 */
function portal_disabled_staging_actions(): array
{
    return [
        'staging',
        'staging_workspace',
        'staging_chunk',
        'staging_finalize',
        'staging_preview_asset',
        'staging_confirm',
        'staging_return',
    ];
}

/**
 * Return a side-effect-free retirement response descriptor, or null when the
 * action is unrelated. Emission remains in index.php so normal security
 * headers, authentication, and page rendering continue to apply.
 */
function portal_disabled_staging_response(string $action): ?array
{
    if (!in_array($action, portal_disabled_staging_actions(), true)) {
        return null;
    }

    if ($action === 'staging') {
        return [
            'status' => 410,
            'content_type' => 'text/html; charset=UTF-8',
            'kind' => 'page',
            'title' => '網站上傳已停用',
            'body' => '網站上傳已停用；Phase 1由管理者於伺服器正式文件庫完成放檔並重新索引。Google Drive舊入口與Bridge僅保留待後續評估。',
        ];
    }

    return [
        'status' => 410,
        'content_type' => 'application/json; charset=utf-8',
        'kind' => 'json',
        'title' => '網站上傳已停用',
        'body' => '網站上傳已停用；Phase 1由管理者於伺服器正式文件庫完成放檔並重新索引。',
    ];
}
