<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'staging_retirement.php';

portal_enforce_https();
portal_send_security_headers(portal_feedback_embed_request_allowed($_GET,(string)($_SERVER['REQUEST_METHOD']??'GET')));
$config = portal_config();
if (!$config['ready']) {
    http_response_code(503);
    portal_render_page(
        '系統設定中',
        '<section class="panel narrow"><p class="eyebrow">PORTAL SETUP</p><h1>文件平台正在設定中</h1>'
        . '<p>PHP、SQL Server 與受保護環境設定尚未完成，因此文件入口尚未開放。</p>'
        . '<p class="muted">完成受控部署後，系統才會提供帳號登入與文件讀取。</p></section>'
    );
    exit;
}

try {
    portal_start_session($config);
    $database = portal_open_database($config);
} catch (Throwable $exception) {
    error_log('TWWATER portal initialization failed: ' . $exception->getMessage());
    http_response_code(503);
    portal_render_page(
        '系統暫時無法使用',
        '<section class="panel narrow"><p class="eyebrow">SERVICE UNAVAILABLE</p><h1>系統暫時無法使用</h1>'
        . '<p>請稍後再試，或聯絡系統管理者。</p></section>'
    );
    exit;
}

// Evaluate env, exact schema/permissions, and fixed-root identity once.
portal_document_mutations_request_enabled($database, $config);

$action = (string) ($_GET['action'] ?? 'documents');
$allowedActions = [
    'documents', 'visual_assets', 'login', 'google_login', 'google_callback', 'logout', 'view', 'download', 'inline',
    'document_version_file', 'document_version_compare', 'document_version_restore',
    'document_version_operation', 'document_version_operation_status',
    'document_replace', 'document_replace_submit', 'document_archive', 'document_restore',
    'presentation', 'presentation_manifest', 'presentation_asset',
    'presentation_annotation_save', 'presentation_annotation_delete',
    'presentation_request_create', 'presentation_request_export', 'presentation_requeue',
    'image_review', 'image_asset', 'image_annotations_save', 'image_annotation_status', 'image_export',
    'image_requirement_revision_create', 'image_requirement_revision_status', 'image_requirement_revision_archive',
    'presentation_queue_internal',
    'html_studio',
    'admin', 'admin_document', 'admin_sync', 'admin_bridge_settings', 'admin_user_create',
    'admin_user_update', 'admin_document_update', 'admin_documents_bulk_update',
    'staging', 'staging_workspace', 'staging_chunk', 'staging_finalize', 'staging_preview_asset', 'staging_confirm', 'staging_return',
    'feedback', 'feedback_batch_create', 'feedback_item_save', 'feedback_item_delete',
    'feedback_submit', 'feedback_status', 'feedback_approve', 'feedback_reject', 'feedback_complete',
    'feedback_batch_archive', 'feedback_batch_restore',
    'feedback_snapshot_save', 'feedback_snapshot_image',
];
if (!in_array($action, $allowedActions, true)) {
    $action = 'documents';
}

try {
    if ($action === 'presentation_queue_internal') {
        portal_handle_presentation_queue_internal($config);
    }
    // The document bridge can wake this existing public entry point without
    // carrying a database password or portal login. Keep that unrelated sync
    // work out of the presentation queue health/trigger endpoint.
    // Resolve bounded ambiguous replacements before normal bridge indexing.
    portal_reconcile_document_mutations($database, $config, null);
    portal_process_bridge_signal($database, $config);
    if ($action === 'login') {
        portal_handle_login($database, $config);
        exit;
    }
    if ($action === 'google_login') {
        portal_handle_google_login($database, $config);
        exit;
    }
    if ($action === 'google_callback') {
        portal_handle_google_callback($database, $config);
        exit;
    }
    if ($action === 'logout') {
        portal_handle_logout($database);
        exit;
    }

    $user = portal_require_user($database);
    $imageCollaborationActions = ['visual_assets','image_review','image_annotations_save','image_annotation_status','image_export','image_requirement_revision_create','image_requirement_revision_status','image_requirement_revision_archive'];
    if (in_array($action,$imageCollaborationActions,true) && !portal_is_admin($user)) {
        $user = portal_require_admin($database);
    }
    $feedbackActions = [
        'feedback','feedback_batch_create','feedback_item_save','feedback_item_delete','feedback_submit','feedback_status',
        'feedback_approve','feedback_reject','feedback_complete','feedback_batch_archive','feedback_batch_restore',
        'feedback_snapshot_save','feedback_snapshot_image',
    ];
    if (in_array($action,$feedbackActions,true) && !portal_is_admin($user)) {
        $user = portal_require_admin($database);
    }
    if (in_array($action,['feedback_approve','feedback_reject'],true)) {
        http_response_code(410);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok'=>false,'error'=>'此審核步驟已取消；系統管理者整理完規格後即可直接協作。'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    $disabledStagingActions = portal_disabled_staging_actions();
    if (in_array($action, $disabledStagingActions, true)) {
        $disabledStagingResponse = portal_disabled_staging_response($action);
        if ($disabledStagingResponse === null) {
            throw new LogicException('停用上傳route缺少回應定義。');
        }
        http_response_code((int) $disabledStagingResponse['status']);
        if ($disabledStagingResponse['kind'] === 'page') {
            portal_render_page(
                (string) $disabledStagingResponse['title'],
                '<section class="panel narrow"><p class="eyebrow">GOOGLE DRIVE ONLY</p><h1>網站上傳已停用</h1>'
                . '<p>' . portal_e((string) $disabledStagingResponse['body']) . '</p>'
                . '<p class="muted">既有暫存資料仍保留，但本網站不再接受新增、分段上傳、確認寫入或退回操作。</p>'
                . '<a class="secondary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>',
                $user
            );
            exit;
        }
        header('Content-Type: ' . (string) $disabledStagingResponse['content_type']);
        echo json_encode(['ok' => false, 'error' => (string) $disabledStagingResponse['body']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'feedback_snapshot_image') {
        portal_handle_feedback_snapshot_image($database,$user,(string)($_GET['id']??''));
    }
    if ($action === 'feedback') {
        portal_render_site_feedback($database, $config, $user, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'feedback_batch_create') {
        portal_handle_feedback_batch_create($database, $user);
    }
    if ($action === 'feedback_item_save') {
        portal_handle_feedback_item_save($database, $user);
    }
    if ($action === 'feedback_item_delete') {
        portal_handle_feedback_item_delete($database, $user);
    }
    if ($action === 'feedback_snapshot_save') {
        portal_handle_feedback_snapshot_save($database,$user);
    }
    if ($action === 'feedback_submit') {
        portal_handle_feedback_submit($database, $config, $user);
    }
    if ($action === 'feedback_status') {
        portal_handle_feedback_status($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }

    if ($action === 'feedback_complete') {
        portal_handle_feedback_complete($database, $user);
    }
    if ($action === 'feedback_batch_archive') {
        portal_handle_feedback_batch_archive($database, $user);
    }
    if ($action === 'feedback_batch_restore') {
        portal_handle_feedback_batch_restore($database, $user);
    }
    if (in_array($action, [
        'documents', 'visual_assets', 'view', 'download', 'inline', 'document_version_file', 'document_version_restore',
        'presentation', 'presentation_manifest', 'presentation_asset',
        'presentation_annotation_save', 'presentation_annotation_delete',
        'presentation_request_create', 'presentation_requeue',
        'image_review', 'image_asset', 'image_annotations_save', 'image_annotation_status', 'image_export',
    'image_requirement_revision_create', 'image_requirement_revision_status', 'image_requirement_revision_archive',
    ], true)
        && portal_bridge_documents_blocked($config)) {
        http_response_code(503);
        header('Retry-After: 5');
        portal_render_page(
            '文件更新中',
            '<section class="panel narrow"><p class="eyebrow">DOCUMENT UPDATE</p><h1>文件正在安全更新</h1>'
            . '<p>系統正在完成文件複製與索引，為避免顯示尚未審核的版本，文件讀取暫時停用。</p>'
            . '<p class="muted">通常只需數秒，請稍後重新整理頁面。</p></section>',
            $user
        );
        exit;
    }
    if ($action === 'staging') {
        $editor = portal_require_editor($database);
        portal_render_staging_workspace($database, $config, $editor, (string) ($_GET['id'] ?? ''), (string) ($_GET['request'] ?? ''), (string) ($_GET['target'] ?? ''));
        exit;
    }
    if ($action === 'staging_workspace') {
        $editor = portal_require_editor($database);
        portal_handle_staging_workspace($database, $config, $editor);
    }
    if ($action === 'staging_chunk') {
        $editor = portal_require_editor($database);
        portal_handle_staging_chunk($database, $config, $editor);
    }
    if ($action === 'staging_finalize') {
        $editor = portal_require_editor($database);
        portal_handle_staging_finalize($database, $config, $editor);
    }
    if ($action === 'staging_preview_asset') {
        $editor = portal_require_editor($database);
        portal_handle_staging_preview_asset($database, $config, $editor, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'staging_confirm') {
        $editor = portal_require_editor($database);
        portal_handle_staging_confirm($database, $config, $editor);
    }
    if ($action === 'staging_return') {
        $editor = portal_require_editor($database);
        portal_handle_staging_return($database, $config, $editor);
    }
    if ($action === 'document_replace') {
        portal_render_document_replace_page($database, $user, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'document_replace_submit') {
        portal_handle_document_replace_submit($database, $config, $user);
        exit;
    }
    if ($action === 'document_archive') {
        portal_handle_document_archive($database, $user);
        exit;
    }
    if ($action === 'document_restore') {
        $admin = portal_require_admin($database);
        portal_handle_document_restore($database, $config, $admin);
        exit;
    }
    if ($action === 'view') {
        portal_render_document_page($database, $config, $user, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'download' || $action === 'inline') {
        portal_handle_file_response($database, $config, $user, (string) ($_GET['id'] ?? ''), $action === 'inline');
        exit;
    }
    if ($action === 'document_version_file') {
        $admin = portal_require_admin($database);
        portal_handle_document_version_file(
            $database,
            $config,
            $admin,
            (string) ($_GET['id'] ?? ''),
            (string) ($_GET['hash'] ?? '')
        );
        exit;
    }
    if ($action === 'document_version_compare') {
        $admin = portal_require_admin($database);
        portal_render_document_version_compare_page(
            $database,
            $config,
            $admin,
            (string) ($_GET['id'] ?? ''),
            (string) ($_GET['hash'] ?? '')
        );
        exit;
    }
    if ($action === 'document_version_restore') {
        $admin = portal_require_admin($database);
        portal_handle_document_version_restore($database, $config, $admin);
        exit;
    }
    if ($action === 'document_version_operation') {
        $admin = portal_require_admin($database);
        portal_render_document_version_operation_result($database, $config, $admin, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'document_version_operation_status') {
        $admin = portal_require_admin($database);
        portal_handle_document_version_operation_status($database, $config, $admin, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'presentation') {
        portal_render_presentation_page($database, $config, $user, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'presentation_manifest') {
        portal_handle_presentation_manifest($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'presentation_asset') {
        portal_handle_presentation_asset(
            $database,
            $config,
            $user,
            (string) ($_GET['id'] ?? ''),
            (string) ($_GET['rendition'] ?? '')
        );
    }
    if ($action === 'presentation_annotation_save') {
        portal_handle_presentation_annotation_save($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'presentation_annotation_delete') {
        portal_handle_presentation_annotation_delete($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'presentation_request_create') {
        portal_handle_presentation_request_create($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'presentation_request_export') {
        portal_handle_presentation_request_export($database, $user, (string) ($_GET['request'] ?? ''));
    }
    if ($action === 'presentation_requeue') {
        portal_handle_presentation_requeue($database, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'visual_assets') {
        portal_render_image_library_page($database, $config, $user);
        exit;
    }
    if ($action === 'image_review') {
        portal_render_image_review($database, $config, $user, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'image_asset') {
        portal_handle_image_asset($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'image_annotations_save') {
        portal_handle_image_annotations_save($database, $config, $user, (string) ($_GET['id'] ?? ''));
    }
    if ($action === 'image_annotation_status') {
        portal_handle_image_annotation_status($database, $config, $user);
    }
    if ($action === 'image_requirement_revision_create') {
        portal_handle_image_requirement_revision_create($database,$config,$user);
    }
    if ($action === 'image_requirement_revision_status') {
        portal_handle_image_requirement_revision_status($database,$config,$user);
    }
    if ($action === 'image_requirement_revision_archive') {
        portal_handle_image_requirement_revision_archive($database,$config,$user);
    }
    if ($action === 'image_export') {
        portal_handle_image_export($database, $config, $user);
    }
    if ($action === 'html_studio') {
        $admin = portal_require_admin($database);
        portal_render_html_studio($admin);
        exit;
    }
    if ($action === 'admin') {
        $admin = portal_require_admin($database);
        portal_render_admin_dashboard($database, $admin);
        exit;
    }
    if ($action === 'admin_document') {
        $admin = portal_require_admin($database);
        portal_render_document_editor($database, $admin, (string) ($_GET['id'] ?? ''));
        exit;
    }
    if ($action === 'admin_sync') {
        $admin = portal_require_admin($database);
        portal_handle_admin_sync($database, $config, $admin);
        exit;
    }
    if ($action === 'admin_bridge_settings') {
        $admin = portal_require_admin($database);
        portal_handle_admin_bridge_settings($database, $admin);
        exit;
    }
    if ($action === 'admin_user_create') {
        $admin = portal_require_admin($database);
        portal_handle_admin_user_create($database, $admin);
        exit;
    }
    if ($action === 'admin_user_update') {
        $admin = portal_require_admin($database);
        portal_handle_admin_user_update($database, $admin);
        exit;
    }
    if ($action === 'admin_document_update') {
        $admin = portal_require_admin($database);
        portal_handle_admin_document_update($database, $admin);
        exit;
    }
    if ($action === 'admin_documents_bulk_update') {
        $admin = portal_require_admin($database);
        portal_handle_admin_documents_bulk_update($database, $admin);
        exit;
    }

    portal_render_document_library($database, $user);
} catch (RuntimeException $exception) {
    error_log('TWWATER portal request failed: ' . $exception->getMessage());
    http_response_code(400);
    portal_render_page(
        '請求無法完成',
        '<section class="panel narrow"><p class="eyebrow">REQUEST NOT COMPLETED</p><h1>請求無法完成</h1>'
        . '<p>請確認操作內容後再試一次。</p><a class="primary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>',
        isset($user) ? $user : null
    );
} catch (Throwable $exception) {
    error_log('TWWATER portal unexpected failure: ' . $exception->getMessage());
    http_response_code(500);
    portal_render_page(
        '系統暫時無法使用',
        '<section class="panel narrow"><p class="eyebrow">SYSTEM ERROR</p><h1>系統暫時無法使用</h1>'
        . '<p>請稍後再試，或聯絡系統管理者。</p></section>',
        isset($user) ? $user : null
    );
}

function portal_handle_login(PDO $database, array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            portal_assert_csrf();
            [$successful, $message] = portal_attempt_login(
                $database,
                $config,
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );
            if ($successful) {
                header('Location: ' . portal_url('documents'), true, 303);
                return;
            }
            portal_render_page(
                '系統登入',
                '<section class="panel login-panel"><p class="eyebrow">SYSTEM LOGIN</p><h1>系統帳號登入</h1>'
                . '<p class="error-message">' . portal_e($message) . '</p>'
                . portal_login_form($config, (string) ($_POST['username'] ?? '')) . '</section>'
            );
            return;
        } catch (RuntimeException $exception) {
            http_response_code(400);
            portal_render_page(
                '系統登入',
                '<section class="panel login-panel"><p class="eyebrow">SYSTEM LOGIN</p><h1>系統帳號登入</h1>'
                . '<p class="error-message">請重新開啟登入頁後再試一次。</p>' . portal_login_form($config) . '</section>'
            );
            return;
        }
    }
    if (portal_current_user($database) !== null) {
        header('Location: ' . portal_url('documents'), true, 303);
        return;
    }
    portal_render_page(
        '系統登入',
        '<section class="panel login-panel"><p class="eyebrow">SYSTEM LOGIN</p><h1>系統帳號登入</h1>'
        . '<p>請使用受授權的系統帳號登入。</p>' . portal_login_form($config) . '</section>'
    );
}

function portal_login_form(array $config, string $username = ''): string
{
    $passwordForm = '<form method="post" action="' . portal_url('login') . '" class="login-form" autocomplete="on">'
        . portal_csrf_field()
        . '<label>帳號<input name="username" type="text" value="' . portal_e($username)
        . '" maxlength="128" pattern="[A-Za-z0-9._-]{3,128}" autocomplete="username" required autofocus></label>'
        . '<label>密碼<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<button type="submit" class="primary-button">登入系統</button></form>';
    if (!($config['google_login_enabled'] ?? false)) {
        return $passwordForm;
    }
    return $passwordForm
        . '<div class="login-divider"><span>或</span></div>'
        . '<a class="google-login-button" href="' . portal_url('google_login') . '"><span aria-hidden="true">G</span> 使用 Google 帳號驗證登入</a>'
        . '<p class="login-help">僅限管理員已預先綁定的 Google 電子郵件地址，不會自動建立新帳號。</p>';
}

function portal_handle_google_login(PDO $database, array $config): void
{
    if (portal_current_user($database) !== null) {
        header('Location: ' . portal_url('documents'), true, 303);
        return;
    }
    try {
        header('Location: ' . portal_google_begin_authorization($config), true, 302);
    } catch (Throwable $exception) {
        error_log('TWWATER Google login start failed: ' . $exception->getMessage());
        portal_render_page('Google 登入尚未啟用', '<section class="panel narrow"><h1>Google 登入尚未啟用</h1><p>請先使用系統帳號登入，或聯絡管理員完成 Google Web OAuth 設定。</p><a class="primary-button" href="' . portal_url('login') . '">返回登入頁</a></section>');
    }
}

function portal_handle_google_callback(PDO $database, array $config): void
{
    $pending = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);
    try {
        if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 600
            || !hash_equals((string) ($pending['state'] ?? ''), (string) ($_GET['state'] ?? ''))
            || isset($_GET['error'])) {
            throw new RuntimeException('Google 登入狀態已失效。');
        }
        $identity = portal_google_exchange_code(
            $config,
            (string) ($_GET['code'] ?? ''),
            (string) ($pending['verifier'] ?? ''),
            (string) ($pending['nonce'] ?? '')
        );
        [$successful, $message] = portal_attempt_google_login($database, $config, $identity);
        if ($successful) {
            header('Location: ' . portal_url('documents'), true, 303);
            return;
        }
        throw new RuntimeException($message);
    } catch (Throwable $exception) {
        error_log('TWWATER Google login callback rejected: ' . $exception->getMessage());
        portal_render_page(
            'Google 登入失敗',
            '<section class="panel login-panel"><p class="eyebrow">GOOGLE LOGIN</p><h1>Google 登入失敗</h1>'
            . '<p class="error-message">此 Google 帳號尚未由管理員綁定，或驗證已失效。</p>'
            . portal_login_form($config) . '</section>'
        );
    }
}

function portal_handle_logout(PDO $database): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
    } catch (RuntimeException $exception) {
        // A stale page can retain an older logout token after the session expires,
        // another tab logs out, or the browser restores the page from history.
        // Keep CSRF fail-closed for an authenticated session, but never strand an
        // already-anonymous browser on the logout endpoint.
        $user = portal_current_user($database);
        if ($user === null) {
            portal_clear_session();
            header('Location: ' . portal_url('login'), true, 303);
            return;
        }
        portal_flash_set('error', '登入狀態已更新，請再按一次登出。');
        header('Location: ' . portal_url('documents'), true, 303);
        return;
    }
    $user = portal_current_user($database);
    if ($user !== null) {
        portal_audit($database, 'logout', 'accepted', (int) $user['user_id'], (string) $user['username']);
    }
    portal_clear_session();
    header('Location: ' . portal_url('login'), true, 303);
}

function portal_render_document_replace_page(PDO $database, array $user, string $publicId): void
{
    if (!portal_document_mutations_request_enabled() || !portal_document_mutation_actor_allowed($user)) { http_response_code(404); portal_render_page('功能尚未啟用','<section class="panel narrow"><h1>功能尚未啟用</h1><p>安全文件替換仍在部署驗證階段。</p></section>',$user); return; }
    $document=portal_get_catalog_document($database,$user,$publicId);
    if ($document===null || (string)$document['status_code']==='archived') { http_response_code(404); portal_render_page('找不到文件','<section class="panel narrow"><h1>找不到文件</h1></section>',$user); return; }
    $content='<a class="back-link" href="'.portal_e(portal_url('view',['id'=>$publicId])).'">← 返回文件</a><section class="panel editor-panel document-replace-panel"><p class="eyebrow">SECURE REPLACEMENT</p><h1>安全替換 '.portal_e((string)$document['file_name']).'</h1><p>檔名、相對路徑與副檔名必須維持不變。目前內容會保留於不可變SQL版本歷程；單檔上限500 MiB。</p><form class="document-replace-form" method="post" enctype="multipart/form-data" action="'.portal_e(portal_url('document_replace_submit')).'">'.portal_csrf_field().'<input type="hidden" name="id" value="'.portal_e($publicId).'"><input type="hidden" name="expected_hash" value="'.portal_e((string)$document['content_hash']).'"><label>選擇替換檔案<input type="file" name="replacement" accept=".'.portal_e((string)$document['extension']).'" required></label><div class="form-actions"><a class="secondary-button" href="'.portal_e(portal_url('view',['id'=>$publicId])).'">取消</a><button class="primary-button" type="submit">安全替換並建立新版本</button></div></form></section>';
    portal_render_page('安全替換文件',$content,$user);
}

function portal_handle_document_replace_submit(PDO $database, array $config, array $user): void
{
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); return; }
    $id=(string)($_POST['id']??'');
    try {
        portal_assert_csrf();
        if (!portal_document_mutations_request_enabled() || !portal_document_mutation_actor_allowed($user)) throw new RuntimeException('功能尚未啟用。');
        $document=portal_get_catalog_document($database,$user,$id);
        if ($document===null || !hash_equals((string)$document['content_hash'],portal_document_version_hash((string)($_POST['expected_hash']??'')))) throw new RuntimeException('文件已變更。');
        portal_execute_document_replacement($database,$config,$document,$user,(array)($_FILES['replacement']??[]));
        portal_flash_set('success','文件已安全替換，新的完整內容已建立不可變 SQL 版本。');
    } catch(Throwable $exception) { error_log('TWWATER secure replacement failed: '.$exception->getMessage()); portal_flash_set('error','替換未完成；若檔案已切換，系統會由持久操作紀錄安全接續。'); }
    header('Location: '.(portal_valid_public_id($id)===''?portal_url('documents'):portal_url('view',['id'=>$id])),true,303);
}

function portal_handle_document_archive(PDO $database, array $user): void
{
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); return; }
    try { portal_assert_csrf(); if(!portal_document_mutations_request_enabled() || !portal_document_mutation_actor_allowed($user)) throw new RuntimeException('功能尚未啟用。'); $document=portal_get_catalog_document($database,$user,(string)($_POST['id']??'')); if($document===null) throw new RuntimeException('找不到文件。'); portal_soft_archive_document($database,$document,$user,(string)($_POST['expected_hash']??'')); portal_flash_set('success','文件已封存；實體檔案、版本內容與稽核均完整保留。'); }
    catch(Throwable $exception) { error_log('TWWATER soft archive failed: '.$exception->getMessage()); portal_flash_set('error','封存未完成，請重新整理後再試。'); }
    header('Location: '.portal_url('documents'),true,303);
}

function portal_handle_document_restore(PDO $database, array $config, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); return; }
    try { portal_assert_csrf(); if(!portal_document_mutations_request_enabled() || !portal_document_mutation_actor_allowed($admin)) throw new RuntimeException('功能尚未啟用。'); $document=portal_get_catalog_document($database,$admin,(string)($_POST['id']??'')); if($document===null) throw new RuntimeException('找不到文件。'); portal_restore_archived_document($database,$config,$document,$admin); portal_flash_set('success','文件已從軟封存恢復；實體檔案與不可變目前版本均已驗證。'); }
    catch(Throwable $exception) { error_log('TWWATER soft restore failed: '.$exception->getMessage()); portal_flash_set('error','只有由使用者軟封存且檔案仍存在的文件可恢復。'); }
    header('Location: '.portal_url('admin'),true,303);
}

function portal_render_document_library(PDO $database, array $user): void
{
    $filters = portal_catalog_filters($_GET);
    $filters['exclude_visual_assets'] = true;
    $filters['exclude_internal_markdown'] = true;
    $documents = portal_list_catalog_documents($database, $user, $filters);
    $phases = portal_get_ssdlc_phases($database);
    $types = portal_get_document_types($database);
    $content = '<section class="hero hero-row"><div><p class="eyebrow">LIVE DOCUMENT LIBRARY</p><h1>即時文件庫</h1>'
        . '<p>本網站自動讀取同步的 document-library；每 60 秒安全掃描一次，穩定的新增、更新與刪除會自動反映。所有開啟與下載都必須先登入並通過授權。</p></div>'
        . '<div class="hero-actions"><span class="live-indicator"><i aria-hidden="true"></i> 自動同步索引</span>'
        . (portal_is_admin($user) ? '<a class="secondary-button" href="' . portal_url('admin') . '">管理後台</a>' : '') . '</div></section>';
    $content .= portal_render_view_tabs($filters);
    $content .= portal_render_library_filters($filters, $phases, $types);
    $content .= portal_render_document_results($documents, $filters, portal_is_admin($user), $user);
    portal_render_page('文件庫', $content, $user, false, 'document-library-page');
}

function portal_render_hidden_values(array $values): string
{
    $hidden = '';
    foreach ($values as $name => $value) {
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            if (!is_scalar($item) || (string) $item === '') {
                continue;
            }
            $fieldName = is_array($value) ? (string) $name . '[]' : (string) $name;
            $hidden .= '<input type="hidden" name="' . portal_e($fieldName) . '" value="' . portal_e((string) $item) . '">';
        }
    }
    return $hidden;
}

function portal_render_view_tabs(array $filters): string
{
    $stage = $filters['view'] === 'stage' ? $filters['stages'] : $filters['stage_states'];
    $role = $filters['view'] === 'stage' ? $filters['roles'] : $filters['role_states'];
    $type = $filters['view'] === 'type' ? $filters['types'] : $filters['type_states'];
    $stageUrl = portal_url('documents', array_filter(['view' => 'stage', 'stage' => $stage, 'role' => $role, 'q' => $filters['search'], 'sort' => $filters['sort'], 'type_state' => $type]));
    $typeUrl = portal_url('documents', array_filter(['view' => 'type', 'type' => $type, 'q' => $filters['search'], 'sort' => $filters['sort'], 'stage_state' => $stage, 'role_state' => $role]));
    $directoryUrl = portal_url('documents', array_filter(['view' => 'directory', 'q' => $filters['search'], 'sort' => $filters['sort'], 'stage_state' => $stage, 'role_state' => $role, 'type_state' => $type]));
    return '<section class="view-switcher panel"><div><p class="eyebrow">DOCUMENT VIEWS</p><h2>以不同視角檢視同一批文件</h2>'
        . '<p>可依 SSDLC 階段、文件種類或網站實際目錄瀏覽；三個面向彼此獨立，切換檢視不會修改文件。</p></div>'
        . '<div class="view-tabs" role="tablist"><a class="view-tab ' . ($filters['view'] === 'stage' ? 'active' : '') . '" role="tab" aria-selected="' . ($filters['view'] === 'stage' ? 'true' : 'false') . '" href="' . $stageUrl . '"><span>依 SSDLC 階段</span><small>輸入／產出脈絡</small></a>'
        . '<a class="view-tab ' . ($filters['view'] === 'type' ? 'active' : '') . '" role="tab" aria-selected="' . ($filters['view'] === 'type' ? 'true' : 'false') . '" href="' . $typeUrl . '"><span>依文件種類</span><small>內容用途分類</small></a>'
        . '<a class="view-tab ' . ($filters['view'] === 'directory' ? 'active' : '') . '" role="tab" aria-selected="' . ($filters['view'] === 'directory' ? 'true' : 'false') . '" href="' . $directoryUrl . '"><span>依網站目錄</span><small>目錄階層瀏覽</small></a></div></section>';
}

function portal_document_sort_labels(): array
{
    return [
        'created_desc' => '建立時間：新到舊',
        'created_asc' => '建立時間：舊到新',
        'modified_desc' => '修改時間：新到舊',
        'modified_asc' => '修改時間：舊到新',
        'name_asc' => '名稱：昇冪',
        'name_desc' => '名稱：降冪',
    ];
}

function portal_render_document_sort_options(string $selectedSort): string
{
    $options = '';
    foreach (portal_document_sort_labels() as $value => $label) {
        $selected = $selectedSort === $value ? ' selected' : '';
        $options .= '<option value="' . $value . '"' . $selected . '>' . portal_e($label) . '</option>';
    }
    return $options;
}

function portal_render_library_sort_menu(array $filters): string
{
    $parameters = ['action' => 'documents', 'view' => $filters['view'], 'q' => $filters['search']];
    if ($filters['view'] === 'stage') {
        $parameters += ['stage' => $filters['stages'], 'role' => $filters['roles'], 'type_state' => $filters['type_states']];
    } elseif ($filters['view'] === 'type') {
        $parameters += ['type' => $filters['types'], 'stage_state' => $filters['stage_states'], 'role_state' => $filters['role_states']];
    } else {
        $parameters += ['stage_state' => $filters['stage_states'], 'role_state' => $filters['role_states'], 'type_state' => $filters['type_states']];
    }
    $hidden = portal_render_hidden_values($parameters);
    $currentLabel = portal_document_sort_labels()[$filters['sort']] ?? portal_document_sort_labels()['modified_desc'];
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h10M4 12h7M4 18h4M17 9v9m0 0-3-3m3 3 3-3"/></svg>';
    return '<form method="get" class="library-sort-menu" data-library-sort-menu>' . $hidden
        . '<details><summary aria-label="文件排序" title="文件排序：' . portal_e($currentLabel) . '">' . $icon . '<span class="library-sort-current">排序</span></summary>'
        . '<div class="library-sort-menu-panel"><label>排序方式<select name="sort">' . portal_render_document_sort_options((string) $filters['sort']) . '</select></label><small>選取後立即重新排列文件。</small><noscript><button class="secondary-button" type="submit">套用排序</button></noscript></div></details></form>';
}

function portal_render_library_checkbox_group(string $legend, string $name, array $options, array $selected): string
{
    $items = '';
    foreach ($options as $value => $label) {
        $checked = in_array((string) $value, $selected, true) ? ' checked' : '';
        $items .= '<label class="library-filter-option"><input type="checkbox" name="' . portal_e($name) . '[]" value="' . portal_e((string) $value) . '"' . $checked . '><span>' . portal_e((string) $label) . '</span></label>';
    }
    return '<fieldset class="library-filter-group"><legend>' . portal_e($legend) . '</legend><div class="library-filter-options">' . $items . '</div></fieldset>';
}

function portal_render_library_filters(array $filters, array $phases, array $types): string
{
    $hidden = ['action' => 'documents', 'view' => $filters['view'], 'sort' => $filters['sort']];
    if ($filters['view'] === 'stage') {
        $hidden['type_state'] = $filters['type_states'];
    } elseif ($filters['view'] === 'type') {
        $hidden['stage_state'] = $filters['stage_states'];
        $hidden['role_state'] = $filters['role_states'];
    } else {
        $hidden['stage_state'] = $filters['stage_states'];
        $hidden['role_state'] = $filters['role_states'];
        $hidden['type_state'] = $filters['type_states'];
    }
    $form = '<form method="get" class="library-filter panel is-' . portal_e((string) $filters['view']) . '">' . portal_render_hidden_values($hidden);
    $form .= '<label class="library-search-field">搜尋<input type="search" name="q" value="' . portal_e($filters['search']) . '" maxlength="120" placeholder="檔名、標題、說明或文件種類"></label>';
    $form .= '<div class="filter-actions"><button class="primary-button" type="submit">套用篩選</button><a class="secondary-button" href="' . portal_url('documents', ['view' => $filters['view'], 'sort' => $filters['sort']]) . '">清除</a></div>';
    if ($filters['view'] === 'stage') {
        $phaseOptions = [];
        foreach ($phases as $phase) {
            $phaseOptions[(string) $phase['phase_code']] = (string) $phase['phase_code'] . ' ' . (string) $phase['display_name'];
        }
        $advanced = portal_render_library_checkbox_group('SSDLC 階段', 'stage', $phaseOptions, $filters['stages'])
            . portal_render_library_checkbox_group('文件角色', 'role', ['input' => '本階段輸入', 'output' => '本階段產出'], $filters['roles']);
        $advancedCount = count($filters['stages']) + count($filters['roles']);
    } elseif ($filters['view'] === 'type') {
        $typeOptions = [];
        foreach ($types as $type) {
            $typeOptions[(string) $type['type_code']] = (string) $type['display_name'];
        }
        $advanced = portal_render_library_checkbox_group('文件種類', 'type', $typeOptions, $filters['types']);
        $advancedCount = count($filters['types']);
    } else {
        return $form . '</form>';
    }
    $advancedOpen = $advancedCount > 0;
    $summary = $advancedCount > 0 ? '進階查詢（已選 ' . $advancedCount . ' 項）' : '進階查詢';
    $form .= '<details class="library-advanced-filter"' . ($advancedOpen ? ' open' : '') . '><summary><span>' . portal_e($summary) . '</span><small>可同時勾選多個條件</small></summary><div class="library-advanced-grid">' . $advanced . '</div></details>';
    return $form . '</form>';
}

function portal_render_stage_context(array $filters, array $phases, array $documents): string
{
    $selectedStages = $filters['stages'];
    if (count($selectedStages) !== 1) {
        $cards = '';
        foreach ($phases as $phase) {
            $code = (string) $phase['phase_code'];
            $count = count(array_filter($documents, static function (array $document) use ($code): bool {
                foreach ($document['phase_roles'] as $role) {
                    if ((string) $role['phase_code'] === $code) {
                        return true;
                    }
                }
                return false;
            }));
            $cards .= '<a class="all-stage-card" href="' . portal_url('documents', array_filter(['view' => 'stage', 'stage' => [$code], 'role' => $filters['roles'], 'q' => $filters['search'], 'sort' => $filters['sort'], 'type_state' => $filters['type_states']])) . '"><span>' . portal_e($code) . '</span><strong>' . portal_e((string) $phase['display_name']) . '</strong><small>' . $count . ' 件已對應</small></a>';
        }
        return '<section class="stage-overview panel"><div class="stage-overview-heading"><div><p class="eyebrow">SSDLC OVERVIEW</p><h2>' . ($selectedStages === [] ? '全部 SSDLC 階段' : '已選擇多個 SSDLC 階段') . '</h2><p>可在進階查詢中同時勾選多個階段；點選下方單一階段可查看完整交接脈絡。</p></div></div><div class="all-stage-grid">' . $cards . '</div></section>';
    }
    $selectedStage = $selectedStages[0];
    $selected = null;
    foreach ($phases as $phase) {
        if ((string) $phase['phase_code'] === $selectedStage) {
            $selected = $phase;
            break;
        }
    }
    if ($selected === null) {
        return '';
    }
    $expected = portal_expected_stage_artifacts((string) $selected['phase_code']);
    $inputCount = count(array_filter($documents, static fn(array $document): bool => portal_document_has_role($document, (string) $selected['phase_code'], 'input')));
    $outputCount = count(array_filter($documents, static fn(array $document): bool => portal_document_has_role($document, (string) $selected['phase_code'], 'output')));
    return '<section class="stage-overview panel"><div class="stage-overview-heading"><div><p class="eyebrow">SSDLC ' . portal_e((string) $selected['phase_code']) . '</p><h2>' . portal_e((string) $selected['phase_code'] . ' ' . (string) $selected['display_name']) . '</h2><p>' . portal_e((string) $selected['description']) . '</p></div><div class="stage-counts"><span><b>' . $inputCount . '</b> 輸入</span><span><b>' . $outputCount . '</b> 產出</span></div></div>'
        . portal_render_expected_artifacts('README 建議輸入', $expected['inputs'], 'input-set')
        . portal_render_expected_artifacts('README 預期產出', $expected['outputs'], 'output-set') . '</section>';
}

function portal_render_expected_artifacts(string $label, array $items, string $class): string
{
    $chips = '';
    foreach ($items as $item) {
        $chips .= '<i>' . portal_e($item) . '</i>';
    }
    return '<div class="expected-artifact-set ' . $class . '"><span>' . portal_e($label) . '</span><div>' . $chips . '</div></div>';
}

function portal_document_has_role(array $document, string $stage, string $role): bool
{
    foreach ($document['phase_roles'] as $link) {
        if ((string) $link['phase_code'] === $stage && (string) $link['role_code'] === $role) {
            return true;
        }
    }
    return false;
}

function portal_render_document_results(array $documents, array $filters, bool $isAdmin, array $user = []): string
{
    if ($filters['view'] === 'directory') {
        return portal_render_document_directory_tree($documents, $filters, $isAdmin, $user);
    }
    $heading = $filters['view'] === 'stage' ? 'SSDLC 文件' : '依文件種類檢視';
    $singleStage = count($filters['stages']) === 1 ? $filters['stages'][0] : null;
    $description = $filters['view'] === 'stage' && $singleStage !== null
        ? '同一份文件可同時是本階段產出與下一階段輸入。'
        : '僅顯示目前帳號具權限讀取的文件。';
    $resultActions = '<div class="library-result-actions"><span class="count-badge">' . count($documents) . ' 件</span>' . portal_render_library_sort_menu($filters) . '</div>';
    if ($documents === []) {
        return '<section class="file-list panel"><div class="section-heading"><div><h2>' . $heading . '</h2><p>' . $description . '</p></div>' . $resultActions . '</div><div class="empty-state">目前沒有符合條件的文件。</div></section>';
    }
    $body = '';
    if ($filters['view'] === 'stage' && $singleStage !== null && count($filters['roles']) !== 1) {
        $inputs = array_values(array_filter($documents, static fn(array $document): bool => portal_document_has_role($document, $singleStage, 'input')));
        $outputs = array_values(array_filter($documents, static fn(array $document): bool => portal_document_has_role($document, $singleStage, 'output')));
        $body .= portal_render_document_group('先備輸入文件', '本階段開始前應確認或承接的文件。', $inputs, $singleStage, $isAdmin, $user);
        $body .= portal_render_document_group('本階段產出文件', '完成本階段後可供後續階段使用的文件。', $outputs, $singleStage, $isAdmin, $user);
    } else {
        foreach ($documents as $document) {
            $body .= portal_render_document_row($document, $filters['view'] === 'stage' ? $singleStage : null, $isAdmin, $user);
        }
    }
    return '<section class="file-list panel"><div class="section-heading"><div><h2>' . $heading . '</h2><p>' . $description . '</p></div>' . $resultActions . '</div><div class="document-rows">' . $body . '</div></section>';
}

function portal_render_document_directory_node(array $node, bool $isAdmin, bool $isRoot = false, array $user = []): string
{
    $children = '';
    foreach ($node['directories'] as $child) {
        $children .= portal_render_document_directory_node($child, $isAdmin, false, $user);
    }
    foreach ($node['documents'] as $document) {
        $children .= portal_render_document_row($document, null, $isAdmin, $user);
    }
    if ($children === '') {
        $children = '<p class="directory-empty-state">此目錄目前沒有可顯示的文件或子目錄。</p>';
    }
    $label = $isRoot ? '/（根目錄）' : (string) $node['name'];
    $path = $isRoot ? '/' : '/' . (string) $node['path'];
    $count = count($node['directories']) + count($node['documents']);
    return '<details class="directory-node' . ($isRoot ? ' directory-root' : '') . '"' . ($isRoot ? ' open' : '') . ' data-directory-path="' . portal_e($path) . '"><summary><span class="directory-node-icon" aria-hidden="true"></span><span class="directory-node-label">' . portal_e($label) . '</span><small>' . portal_e($path) . '</small><b>' . $count . ' 個項目</b></summary><div class="directory-node-children">' . $children . '</div></details>';
}

function portal_render_document_directory_tree(array $documents, array $filters, bool $isAdmin, array $user = []): string
{
    $tree = portal_build_document_directory_tree($documents);
    $actions = '<div class="library-result-actions"><span class="count-badge">' . count($documents) . ' 件</span>' . portal_render_library_sort_menu($filters) . '</div>';
    return '<section class="file-list panel directory-view"><div class="section-heading"><div><h2>依網站目錄檢視</h2><p>由 / 根目錄開始，依文件的實際相對路徑逐層瀏覽；目錄可展開或收合。</p></div>' . $actions . '</div><div class="directory-tree">' . portal_render_document_directory_node($tree, $isAdmin, true, $user) . '</div></section>';
}

function portal_render_document_group(string $title, string $description, array $documents, string $stage, bool $isAdmin, array $user = []): string
{
    $rows = '';
    foreach ($documents as $document) {
        $rows .= portal_render_document_row($document, $stage, $isAdmin, $user);
    }
    if ($rows === '') {
        $rows = '<p class="group-empty-note">目前尚未列入對應文件；可由管理後台重新索引並補上中繼資料。</p>';
    }
    return '<section class="artifact-group"><div class="artifact-group-heading"><div><h3>' . portal_e($title) . '</h3><p>' . portal_e($description) . '</p></div><span class="count-badge">' . count($documents) . ' 件</span></div><div class="artifact-group-rows">' . $rows . '</div></section>';
}

function portal_render_document_type_tags(array $document): string
{
    $types = (array) ($document['document_types'] ?? []);
    if ($types === []) {
        return '<span class="file-category muted-category">未分類</span>';
    }
    $tags = '';
    foreach ($types as $type) {
        $tags .= '<span class="file-category">' . portal_e((string) $type['display_name']) . '</span>';
    }
    return $tags;
}

function portal_render_document_row(array $document, ?string $focusStage, bool $isAdmin, array $user = []): string
{
    // Files may be synchronized from an external desktop client, but every
    // view/download still passes through the authenticated Portal route.
    $link = portal_url('view', ['id' => (string) $document['public_id']]);
    $status = $isAdmin ? '<span class="status-chip ' . portal_e((string) $document['status_code']) . '">' . portal_e((string) $document['status_code']) . '</span>' : '';
    $relativePath = (string) ($document['relative_path'] ?? '');
    $mutationActions = portal_render_document_mutation_actions($document, $user);
    return '<article class="document-row"><span class="file-icon ' . portal_e((string) $document['extension']) . '">' . portal_e(strtoupper((string) $document['extension'])) . '</span><div class="document-row-main"><a class="document-row-title" href="' . $link . '">' . portal_e((string) $document['title']) . '</a><div class="document-row-detail">' . portal_e((string) ($document['summary'] ?: $document['file_name'])) . '</div><div class="document-row-path"><span>相對路徑</span><code title="' . portal_e($relativePath) . '">' . portal_e($relativePath) . '</code></div><div class="document-row-tags">' . portal_render_document_type_tags($document) . portal_render_phase_roles($document['phase_roles'], $focusStage) . $status . '</div>' . $mutationActions . '</div><div class="document-row-meta"><span>' . portal_e(strtoupper((string) $document['extension'])) . ' · ' . portal_e(portal_format_file_size((int) $document['file_size_bytes'])) . '</span><time>建立 ' . portal_e(portal_format_datetime((string) $document['created_at'])) . '</time><time>修改 ' . portal_e(portal_format_datetime((string) $document['source_modified_at'])) . '</time></div></article>';
}

function portal_render_document_page(PDO $database, array $config, array $user, string $publicId): void
{
    $document = portal_get_catalog_document($database, $user, $publicId);
    if ($document === null) {
        http_response_code(404);
        portal_render_page('文件無法開啟', '<section class="panel narrow"><h1>文件無法開啟</h1><p>找不到文件或沒有閱讀權限。</p><a class="primary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>', $user);
        return;
    }
    try {
        $path = portal_document_file_path($config, $document);
    } catch (RuntimeException $exception) {
        portal_document_audit($database, 'document_view', 'rejected', (int) $user['user_id'], (int) $document['document_id'], 'source unavailable');
        http_response_code(404);
        portal_render_page('文件來源無法使用', '<section class="panel narrow"><h1>文件來源無法使用</h1><p>此文件的來源尚未就緒，請聯絡管理員重新索引。</p><a class="primary-button" href="' . portal_url('documents') . '">返回文件庫</a></section>', $user);
        return;
    }
    portal_document_audit($database, 'document_view', 'accepted', (int) $user['user_id'], (int) $document['document_id']);
    $downloadUrl = portal_url('download', ['id' => (string) $document['public_id']]);
    $actions = '<a class="secondary-button" href="' . $downloadUrl . '">下載原始檔</a>';
    if ((string) $document['extension'] === 'pdf') {
        $actions .= '<a class="primary-button" target="_blank" rel="noopener" href="' . portal_url('inline', ['id' => (string) $document['public_id']]) . '">開啟 PDF</a>';
    }
    if ((string) $document['extension'] === 'pptx') {
        $presentationLabel = portal_presentation_schema_available($database)
            ? '開啟瀏覽器簡報檢視與標注'
            : '開啟瀏覽器簡報預覽';
        $actions .= '<a class="primary-button" href="' . portal_url('presentation', ['id' => (string) $document['public_id']]) . '">' . $presentationLabel . '</a>';
    }
    if ((string) $document['extension'] === 'md') {
        $actions .= '<a class="primary-button" href="' . portal_url('presentation', ['id' => (string) $document['public_id']]) . '">開啟 Markdown 線上檢視與標注</a>';
    }
    if (portal_is_image_extension((string) $document['extension'])) {
        $actions .= '<a class="primary-button" href="' . portal_url('image_review', ['id' => (string) $document['public_id']]) . '">預覽與框選標註</a>';
    }
    $metadata = '<dl class="metadata-list"><div><dt>文件種類</dt><dd><span class="document-type-tag-list">' . portal_render_document_type_tags($document) . '</span></dd></div><div><dt>檔案格式</dt><dd>' . portal_e(strtoupper((string) $document['extension'])) . '</dd></div><div><dt>檔案大小</dt><dd>' . portal_e(portal_format_file_size((int) $document['file_size_bytes'])) . '</dd></div><div><dt>來源更新</dt><dd>' . portal_e(portal_format_datetime((string) $document['source_modified_at'])) . '</dd></div>';
    if (portal_is_admin($user)) {
        $metadata .= '<div><dt>發布狀態</dt><dd><span class="status-chip ' . portal_e((string) $document['status_code']) . '">' . portal_e((string) $document['status_code']) . '</span></dd></div>';
    }
    $metadata .= '</dl>';
    $lifecycle = '<ol class="lifecycle-list">';
    foreach ($document['phase_roles'] as $role) {
        $lifecycle .= '<li class="' . portal_e((string) $role['role_code']) . '"><span>' . portal_e((string) $role['phase_code']) . '</span><div><strong>' . portal_e((string) $role['display_name']) . '</strong><small>' . ((string) $role['role_code'] === 'output' ? '本階段產出' : '本階段輸入') . '</small></div></li>';
    }
    $lifecycle .= $document['phase_roles'] === [] ? '<li><div><strong>尚未標記</strong><small>請由管理後台設定 SSDLC 關聯。</small></div></li>' : '';
    $lifecycle .= '</ol>';
    $preview = '<section class="panel file-preview"><h2>檔案預覽</h2><p>此檔案格式請以下載原始檔方式閱讀。</p></section>';
    if (in_array((string) $document['extension'], ['md', 'txt'], true)) {
        $size = filesize($path);
        if ($size !== false && $size <= 2097152) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('文件讀取失敗。');
            }
            $preview = '<article class="document-text">' . nl2br(portal_e($content), false) . '</article>';
        } else {
            $preview = '<section class="panel file-preview"><h2>檔案預覽</h2><p>文字檔超過可安全預覽的大小，請下載原始檔閱讀。</p></section>';
        }
    }
    if (portal_is_image_extension((string) $document['extension'])) {
        $preview = '<figure class="panel file-preview"><img style="max-width:100%;height:auto" src="' . portal_url('image_asset', ['id' => (string) $document['public_id']]) . '" alt="' . portal_e((string) $document['title']) . '"></figure>';
    }
    $adminEdit = portal_is_admin($user) ? '<a class="text-link" href="' . portal_url('admin_document', ['id' => (string) $document['public_id']]) . '">編輯文件中繼資料</a>' : '';
    $versionPanel = '';
    $blobVersionsEnabled = portal_document_version_blob_schema_ready($database);
    if (portal_is_admin($user) && (($config['document_version_enabled'] ?? false) === true || $blobVersionsEnabled)) {
        try {
            $versionConfig = portal_document_version_config_for_document($config, $document);
            if ($blobVersionsEnabled) {
                $versionConfig['document_version_enabled'] = true;
            }
            portal_sync_document_version_operation_results($database, $versionConfig);
            $activeOperation = portal_get_active_document_version_operation($database, (int) $document['document_id']);
            if ($activeOperation !== null) {
                portal_refresh_document_version_operation($database, $config, (string) $activeOperation['operation_id']);
            }
            $versionNumbers = portal_get_document_version_numbers($database, (int) $document['document_id']);
            $databaseVersions = portal_get_database_document_versions($database, $document);
            $versionOperations = portal_list_document_version_operations($database, (int) $document['document_id']);
            $versionPanel = portal_render_document_version_panel($versionConfig, $document, $user, $versionNumbers, $versionOperations, $databaseVersions);
        } catch (Throwable $versionException) {
            error_log('TWWATER document version panel failed: ' . $versionException->getMessage());
            $versionPanel = '<section class="panel version-panel"><h2>版本管理</h2><p class="muted">版本資訊目前無法讀取，請稍後再試。</p></section>';
        }
    }
    $content = '<a class="back-link" href="' . portal_url('documents') . '">← 返回文件庫</a><div class="document-layout"><article class="document-main panel"><header class="document-header"><div><p class="eyebrow">DOCUMENT VIEW</p><h1>' . portal_e((string) $document['title']) . '</h1><p class="document-subtitle">' . portal_e((string) ($document['summary'] ?: $document['file_name'])) . '</p></div><div class="document-actions">' . $actions . '</div></header><div class="document-preview-meta"><span>' . portal_e((string) $document['file_name']) . '</span><span class="document-type-tag-list">' . portal_render_document_type_tags($document) . '</span></div>' . $preview . '</article><aside class="document-aside"><section class="panel metadata-card"><h2>文件資訊</h2>' . $metadata . $adminEdit . '</section><section class="panel lifecycle-card"><p class="eyebrow">SSDLC TRACEABILITY</p><h2>生命週期關聯</h2>' . $lifecycle . '</section><section class="panel discord-card"><p class="eyebrow">REQUEST CHANGE</p><h2>需要調整內容？</h2><p>請在 Discord 說明要修改的文件、段落與內容；文件橋接器會自動更新索引，內容變更後需由管理者重新確認發布狀態。</p></section></aside></div>' . $versionPanel;
    portal_render_page((string) $document['title'], $content, $user);
}

function portal_handle_file_response(PDO $database, array $config, array $user, string $publicId, bool $inline): void
{
    $document = portal_get_catalog_document($database, $user, $publicId);
    if ($document === null) {
        http_response_code(404);
        portal_render_page('文件無法下載', '<section class="panel narrow"><h1>文件無法下載</h1><p>找不到文件或沒有閱讀權限。</p></section>', $user);
        return;
    }
    try {
        $path = portal_document_file_path($config, $document);
    } catch (RuntimeException $exception) {
        portal_document_audit($database, $inline ? 'document_inline' : 'document_download', 'rejected', (int) $user['user_id'], (int) $document['document_id'], 'source unavailable');
        http_response_code(404);
        portal_render_page('文件來源無法使用', '<section class="panel narrow"><h1>文件來源無法使用</h1><p>請稍後再試。</p></section>', $user);
        return;
    }
    if ($inline && !portal_document_inline_allowed((string) $document['extension'])) {
        http_response_code(400);
        portal_render_page('請求無效', '<section class="panel narrow"><h1>請求無效</h1><p>此檔案格式不支援瀏覽器內讀取。</p></section>', $user);
        return;
    }
    portal_document_audit($database, $inline ? 'document_inline' : 'document_download', 'accepted', (int) $user['user_id'], (int) $document['document_id']);
    portal_stream_document($path, $document, $inline);
}

function portal_handle_document_version_file(PDO $database, array $config, array $admin, string $publicId, string $contentHash): void
{
    $document = portal_get_catalog_document($database, $admin, $publicId);
    $versionConfig = $document === null ? $config : portal_document_version_config_for_document($config, $document);
    $blobVersionsEnabled = $document !== null && portal_document_version_blob_schema_ready($database);
    if ($document === null || (($versionConfig['document_version_enabled'] ?? false) !== true && !$blobVersionsEnabled)) {
        http_response_code(404);
        portal_render_page('版本無法下載', '<section class="panel narrow"><h1>版本無法下載</h1><p>找不到文件或版本管理尚未啟用。</p></section>', $admin);
        return;
    }
    foreach (portal_get_database_document_versions($database, $document) as $databaseVersion) {
        if (($databaseVersion['has_content'] ?? false) === true
            && hash_equals((string) ($databaseVersion['content_hash'] ?? ''), portal_document_version_hash($contentHash))) {
            if (portal_stream_database_document_version($database, $config, $document, $contentHash, $admin)) {
                return;
            }
            portal_document_audit($database, 'document_version_download', 'rejected', (int) $admin['user_id'], (int) $document['document_id'], 'sql integrity failed');
            break;
        }
    }
    $manifest = portal_read_document_version_manifest($versionConfig, $document, $contentHash);
    if ($manifest === null) {
        portal_document_audit($database, 'document_version_download', 'rejected', (int) $admin['user_id'], (int) $document['document_id'], 'archive unavailable');
        http_response_code(404);
        portal_render_page('版本無法下載', '<section class="panel narrow"><h1>版本無法下載</h1><p>封存版本不存在或完整性驗證失敗。</p></section>', $admin);
        return;
    }
    portal_document_audit(
        $database,
        'document_version_download',
        'accepted',
        (int) $admin['user_id'],
        (int) $document['document_id'],
        'hash=' . substr((string) $manifest['content_hash'], 0, 12)
    );
    portal_stream_document(
        (string) $manifest['artifact_path'],
        $document,
        false,
        (string) $manifest['content_hash'],
        (int) $manifest['file_size_bytes']
    );
}

function portal_render_document_version_compare_page(PDO $database, array $config, array $admin, string $publicId, string $targetHash): void
{
    $document = portal_get_catalog_document($database, $admin, $publicId);
    $versionConfig = $document === null ? $config : portal_document_version_config_for_document($config, $document);
    $targetHash = portal_document_version_hash($targetHash);
    $blobVersionsEnabled = $document !== null && portal_document_version_blob_schema_ready($database);
    if ($document === null || $targetHash === '' || (($versionConfig['document_version_enabled'] ?? false) !== true && !$blobVersionsEnabled)) {
        http_response_code(404);
        portal_render_page('版本比較不存在', '<section class="panel narrow"><h1>版本比較不存在</h1><p>找不到指定文件或封存版本。</p></section>', $admin);
        return;
    }
    $numbers = portal_get_document_version_numbers($database, (int) $document['document_id']);
    $target = null;
    $versions = portal_merge_document_versions(
        portal_get_database_document_versions($database, $document),
        portal_list_document_versions($versionConfig, $document, $numbers)
    );
    foreach ($versions as $version) {
        if (($version['is_current'] ?? true) === false && hash_equals((string) $version['content_hash'], $targetHash)) {
            $target = $version;
            break;
        }
    }
    if ($target === null) {
        http_response_code(404);
        portal_render_page('版本比較不存在', '<section class="panel narrow"><h1>版本比較不存在</h1><p>封存版本不存在或完整性驗證失敗。</p></section>', $admin);
        return;
    }
    portal_sync_document_version_operation_results($database, $config);
    $active = portal_get_active_document_version_operation($database, (int) $document['document_id']);
    if ($active !== null) {
        $active = portal_refresh_document_version_operation($database, $config, (string) $active['operation_id']) ?? $active;
    }
    $imageScope = (string) ($versionConfig['document_version_scope'] ?? 'document') === 'image-source';
    $content = '<a class="back-link" href="' . portal_e(portal_url($imageScope ? 'image_review' : 'view', ['id' => (string) $document['public_id']])) . '">← 返回文件</a>'
        . portal_render_document_version_compare(
            $document,
            $target,
            $admin,
            $active,
            ($versionConfig['document_restore_enabled'] ?? false) === true,
            (string) ($versionConfig['document_version_scope'] ?? 'document')
        );
    portal_render_page('版本比較與還原確認', $content, $admin);
}

function portal_render_document_version_operation_result(PDO $database, array $config, array $admin, string $operationId): void
{
    $operation = portal_refresh_document_version_operation($database, $config, $operationId);
    if ($operation === null) {
        http_response_code(404);
        portal_render_page('找不到版本操作', '<section class="panel narrow"><h1>找不到版本操作</h1><p>操作識別碼不存在或資料庫尚未安裝。</p></section>', $admin);
        return;
    }
    $document = portal_get_catalog_document($database, $admin, (string) $operation['document_public_id']);
    if ($document === null) {
        http_response_code(404);
        portal_render_page('找不到版本操作', '<section class="panel narrow"><h1>找不到文件</h1></section>', $admin);
        return;
    }
    $events = portal_get_document_version_operation_events($database, (string) $operation['operation_id']);
    $content = '<a class="back-link" href="' . portal_e(portal_url('view', ['id' => (string) $document['public_id']])) . '">← 返回文件</a>'
        . portal_render_document_version_operation_page($document, $operation, $events);
    portal_render_page('版本操作結果', $content, $admin);
}

function portal_handle_document_version_operation_status(PDO $database, array $config, array $admin, string $operationId): void
{
    $operation = portal_refresh_document_version_operation($database, $config, $operationId);
    if ($operation === null) {
        portal_document_version_json_response(['error' => 'NOT_FOUND'], 404);
    }
    $document = portal_get_catalog_document($database, $admin, (string) $operation['document_public_id']);
    if ($document === null) {
        portal_document_version_json_response(['error' => 'NOT_FOUND'], 404);
    }
    $catalog = portal_document_version_operation_status_catalog();
    $status = (string) $operation['status_code'];
    portal_document_version_json_response([
        'operation_id' => (string) $operation['operation_id'],
        'status' => $status,
        'terminal' => (bool) ($catalog[$status]['terminal'] ?? true),
        'drive_sync_status' => (string) $operation['drive_sync_status'],
        'updated_at' => (string) $operation['updated_at'],
        'url' => portal_url('document_version_operation', ['id' => (string) $operation['operation_id']]),
    ]);
}

function portal_handle_document_version_restore(PDO $database, array $config, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    $publicId = (string) ($_POST['id'] ?? '');
    try {
        portal_assert_csrf();
        $document = portal_get_catalog_document($database, $admin, $publicId);
        if ($document === null) {
            throw new RuntimeException('找不到指定文件。');
        }
        $versionConfig = portal_document_version_config_for_document($config, $document);
        if (($versionConfig['document_restore_enabled'] ?? false) !== true) {
            throw new RuntimeException('此文件的版本還原尚未啟用。');
        }
        $result = portal_create_document_version_restore_operation(
            $database,
            $versionConfig,
            $document,
            (string) ($_POST['hash'] ?? ''),
            $admin
        );
        $operationId = (string) $result['operation_id'];
        portal_document_audit(
            $database,
            'document_version_restore_requested',
            ($result['duplicate'] ?? false) ? 'duplicate' : 'accepted',
            (int) $admin['user_id'],
            (int) $document['document_id'],
            'operation=' . $operationId
        );
        portal_flash_set(
            ($result['duplicate'] ?? false) ? 'error' : 'success',
            ($result['duplicate'] ?? false)
                ? '此文件已有進行中的版本操作，已帶您前往既有操作狀態頁。'
                : '版本還原已排入處理；頁面會自動更新 queued、running、indexed 與 preview-ready 狀態。'
        );
        header('Location: ' . portal_url('document_version_operation', ['id' => $operationId]), true, 303);
        return;
    } catch (Throwable $exception) {
        error_log('TWWATER document version restore request failed: ' . $exception->getMessage());
        portal_flash_set('error', '無法送出版本還原要求；版本可能已變更，請重新整理後再試。');
    }
    $redirectId = portal_valid_public_id($publicId);
    header('Location: ' . ($redirectId === '' ? portal_url('documents') : portal_url('view', ['id' => $redirectId])), true, 303);
}

function portal_render_admin_dashboard(PDO $database, array $admin): void
{
    $users = portal_list_admin_users($database);
    $filters = portal_admin_catalog_filters($_GET);
    $filters['include_archived'] = true;
    $documents = portal_list_catalog_documents($database, $admin, $filters);
    $types = portal_get_document_types($database);
    $phases = portal_get_ssdlc_phases($database);
    $recentDocuments = portal_list_recent_admin_documents($database, 10);
    $bridgeInterval = portal_bridge_settings_read(portal_bridge_settings_path());
    $content = '<section class="hero hero-row"><div><p class="eyebrow">ADMINISTRATION</p><h1>管理後台</h1><p>管理帳號、同步受保護文件來源，並分別設定文件種類、發布狀態與 SSDLC 開發階段關聯。</p></div><div class="hero-actions"><a class="secondary-button" href="' . portal_url('documents') . '">返回文件庫</a></div></section>';
    $content .= '<section class="admin-actions panel"><div><h2>文件索引與漏失校正</h2><p>管理者完成放檔後，再由此處重新索引受保護正式文件庫。Google Drive舊入口與Bridge暫時保留待後續評估；新文件與內容有變更的已發布文件都會先進入草稿，完成審核後才能發布給閱讀者。</p></div>'
        . '<form method="post" action="' . portal_url('admin_bridge_settings') . '" class="bridge-settings-form">' . portal_csrf_field()
        . '<label>漏失校正頻率（秒）<input type="number" name="full_reconcile_seconds" min="30" max="3600" step="1" required value="' . $bridgeInterval . '"></label>'
        . '<small>可設定 30–3600 秒；即時偵測約 3 秒的設定不受影響。</small><button class="primary-button" type="submit">儲存同步頻率</button></form>'
        . '<form method="post" action="' . portal_url('admin_sync') . '">' . portal_csrf_field() . '<button class="secondary-button" type="submit">立即重新索引受控快取</button></form></section>';
    $content .= portal_render_admin_catalog_filters($filters, $types);
    $content .= portal_render_admin_document_table($documents, $types, $phases);
    $content .= portal_render_recent_admin_documents($recentDocuments);
    $content .= portal_render_admin_user_forms($users);
    portal_render_page('管理後台', $content, $admin, false, 'wide-readable-page admin-dashboard-page');
}

function portal_render_admin_catalog_filters(array $filters, array $types): string
{
    $typeOptions = '<option value="">全部文件種類</option>';
    foreach ($types as $type) {
        $code = (string) $type['type_code'];
        $typeOptions .= '<option value="' . portal_e($code) . '"' . ($filters['type'] === $code ? ' selected' : '') . '>'
            . portal_e((string) $type['display_name']) . '</option>';
    }
    $extensionOptions = '<option value="">全部副檔名</option>';
    foreach (['md', 'txt', 'pdf', 'docx', 'xlsx', 'pptx'] as $extension) {
        $extensionOptions .= '<option value="' . $extension . '"' . ($filters['extension'] === $extension ? ' selected' : '') . '>' . strtoupper($extension) . '</option>';
    }
    $modifiedOptions = '';
    foreach (['' => '不限時間', '7d' => '最近 7 天', '30d' => '最近 30 天', '90d' => '最近 90 天', '365d' => '最近 1 年'] as $value => $label) {
        $modifiedOptions .= '<option value="' . $value . '"' . ($filters['modified'] === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $sortOptions = '';
    foreach ([
        'modified_desc' => '來源更新：新到舊', 'modified_asc' => '來源更新：舊到新',
        'filename_asc' => '檔名：A 到 Z', 'filename_desc' => '檔名：Z 到 A',
        'title_asc' => '標題：A 到 Z', 'title_desc' => '標題：Z 到 A',
        'extension_asc' => '副檔名：A 到 Z', 'extension_desc' => '副檔名：Z 到 A',
    ] as $value => $label) {
        $sortOptions .= '<option value="' . $value . '"' . ($filters['sort'] === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return '<form method="get" class="panel admin-catalog-filter"><input type="hidden" name="action" value="admin">'
        . '<label class="admin-search-field">檔名搜尋<input type="search" name="file_q" maxlength="120" value="' . portal_e($filters['filename_search']) . '" placeholder="輸入完整或部分檔名"></label>'
        . '<label>文件種類<select name="type">' . $typeOptions . '</select></label>'
        . '<label>更新時間<select name="modified">' . $modifiedOptions . '</select></label>'
        . '<label>副檔名<select name="extension">' . $extensionOptions . '</select></label>'
        . '<label>排序條件<select name="sort">' . $sortOptions . '</select></label>'
        . '<div class="filter-actions"><button class="primary-button" type="submit">套用</button><a class="secondary-button" href="' . portal_url('admin') . '">清除</a></div></form>';
}

function portal_render_admin_document_table(array $documents, array $types, array $phases): string
{
    $rows = '';
    foreach ($documents as $document) {
        $rows .= '<tr><td class="selection-cell"><input type="checkbox" name="document_ids[]" value="' . portal_e((string) $document['public_id']) . '" data-document-checkbox aria-label="選取 ' . portal_e((string) $document['file_name']) . '"></td>'
            . '<td><strong>' . portal_e((string) $document['title']) . '</strong><small>' . portal_e((string) $document['file_name']) . '</small></td>'
            . '<td><span class="file-extension-badge">' . portal_e(strtoupper((string) $document['extension'])) . '</span></td>'
            . '<td><div class="document-type-tag-list">' . portal_render_document_type_tags($document) . '</div></td>'
            . '<td><span class="status-chip ' . portal_e((string) $document['status_code']) . '">' . portal_e((string) $document['status_code']) . '</span></td>'
            . '<td><time datetime="' . portal_e((string) $document['source_modified_at']) . '">' . portal_e(portal_format_datetime((string) $document['source_modified_at'])) . '</time></td>'
            . '<td>' . portal_render_phase_roles($document['phase_roles']) . '</td>'
            . '<td><a class="secondary-button compact-button" href="' . portal_url('admin_document', ['id' => (string) $document['public_id']]) . '">設定</a>'
            . ((string)$document['status_code']==='archived' && portal_document_mutations_request_enabled() ? '<form method="post" action="'.portal_e(portal_url('document_restore')).'">'.portal_csrf_field().'<input type="hidden" name="id" value="'.portal_e((string)$document['public_id']).'"><button class="secondary-button compact-button" type="submit">解除封存</button></form>' : '')
            . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="8" class="empty-state">沒有符合目前搜尋與篩選條件的文件。</td></tr>';
    }
    $typeChecks = '';
    foreach ($types as $type) {
        $typeChecks .= '<label class="batch-check"><input type="checkbox" name="type_codes[]" value="' . portal_e((string) $type['type_code']) . '"><span>' . portal_e((string) $type['display_name']) . '</span></label>';
    }
    $phaseChecks = '';
    foreach ($phases as $phase) {
        foreach (['input' => '輸入', 'output' => '產出'] as $role => $label) {
            $phaseChecks .= '<label class="batch-check"><input type="checkbox" name="phase_roles[]" value="' . portal_e((string) $phase['phase_code'] . ':' . $role) . '"><span>' . portal_e((string) $phase['phase_code'] . ' ' . (string) $phase['display_name'] . '－' . $label) . '</span></label>';
        }
    }
    $typeModeOptions = '<option value="unchanged">維持不變（不套用）</option>'
        . '<option value="replace">取代：完全改成所選種類</option>'
        . '<option value="add">新增：保留原種類，再加入所選種類</option>'
        . '<option value="remove">移除：只移除所選種類</option>';
    $phaseModeOptions = '<option value="unchanged">維持不變（不套用）</option>'
        . '<option value="replace">取代：完全改成所選角色</option>'
        . '<option value="add">新增：保留原角色，再加入所選角色</option>'
        . '<option value="remove">移除：只移除所選角色</option>';
    $batchEditor = '<div class="batch-editor"><div class="batch-editor-heading"><div><strong>批次屬性設定</strong><small>先選取文件，再為要修改的屬性選擇套用方式，最後勾選細項；維持不變的屬性不會修改。</small></div><span data-selected-document-count>已選 0 件</span></div>'
        . '<div class="batch-controls"><label>發布狀態<select name="status_code"><option value="">維持不變</option><option value="draft">草稿</option><option value="published">已發布</option><option value="archived">封存</option></select></label>'
        . '<details data-batch-panel><summary>文件種類 <span data-batch-mode-label>維持不變</span></summary><div class="batch-mode-intro"><strong>啟用文件種類批次變更</strong><label>套用方式<select name="type_mode" data-batch-mode data-target="batch-type-options">' . $typeModeOptions . '</select></label><p>先選取代、新增或移除，再勾選要套用的文件種類。</p></div><fieldset id="batch-type-options" class="batch-option-fieldset" disabled><legend>選擇文件種類細項</legend><div class="batch-option-grid">' . $typeChecks . '</div></fieldset></details>'
        . '<details data-batch-panel><summary>SSDLC 階段角色 <span data-batch-mode-label>維持不變</span></summary><div class="batch-mode-intro"><strong>啟用 SSDLC 階段角色批次變更</strong><label>套用方式<select name="phase_mode" data-batch-mode data-target="batch-phase-options">' . $phaseModeOptions . '</select></label><p>先選取代、新增或移除，再勾選要套用的階段角色。</p></div><fieldset id="batch-phase-options" class="batch-option-fieldset" disabled><legend>選擇 SSDLC 階段角色細項</legend><div class="batch-option-grid phase-options">' . $phaseChecks . '</div></fieldset></details>'
        . '<button class="primary-button" type="submit">套用至所選文件</button></div></div>';
    return '<section class="panel admin-section"><div class="section-heading"><div><p class="eyebrow">DOCUMENT CATALOG</p><h2>文件目錄</h2><p>可搜尋檔名、組合篩選與排序，並對勾選文件批次設定屬性。</p></div><span class="count-badge">' . count($documents) . ' 件</span></div>'
        . '<form method="post" action="' . portal_url('admin_documents_bulk_update') . '" class="admin-batch-form" data-confirm="確定要將所選屬性套用到勾選的文件嗎？">' . portal_csrf_field() . $batchEditor
        . '<div class="table-wrap"><table class="admin-table"><thead><tr><th class="selection-cell"><input type="checkbox" data-select-all-documents aria-label="選取目前清單全部文件"></th><th>文件</th><th>副檔名</th><th>文件種類</th><th>狀態</th><th>來源更新</th><th>SSDLC 階段</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></form></section>';
}

function portal_render_recent_admin_documents(array $documents): string
{
    $items = '';
    foreach ($documents as $document) {
        $items .= '<li><span class="file-extension-badge">' . portal_e(strtoupper((string) $document['extension'])) . '</span><div><strong>' . portal_e((string) $document['title']) . '</strong><small>' . portal_e((string) $document['file_name']) . '</small></div><div class="recent-document-meta"><span>' . portal_e((string) $document['updated_by_name']) . '</span><time datetime="' . portal_e((string) $document['updated_at']) . '">' . portal_e(portal_format_datetime((string) $document['updated_at'])) . '</time></div><span class="status-chip ' . portal_e((string) $document['status_code']) . '">' . portal_e((string) $document['status_code']) . '</span><a class="secondary-button compact-button" href="' . portal_url('admin_document', ['id' => (string) $document['public_id']]) . '">設定</a></li>';
    }
    if ($items === '') {
        $items = '<li class="empty-state">尚無文件編輯紀錄。</li>';
    }
    return '<section class="panel admin-section recent-documents"><div class="section-heading"><div><p class="eyebrow">RECENTLY EDITED</p><h2>最近編輯文件</h2><p>依文件中繼資料最後更新時間列出最近 10 筆紀錄。</p></div></div><ol class="recent-document-list">' . $items . '</ol></section>';
}

function portal_render_admin_user_forms(array $users): string
{
    $create = '<form method="post" action="' . portal_url('admin_user_create') . '" class="admin-form create-user-form">' . portal_csrf_field() . '<h3>建立系統帳號</h3><label>帳號<input name="username" maxlength="128" pattern="[A-Za-z0-9._-]{3,128}" required></label><label>顯示名稱<input name="display_name" maxlength="100" required></label><label>角色<select name="role_code"><option value="reader">閱讀者</option><option value="editor">編輯者</option><option value="admin">管理員</option></select></label><label>Google 登入 Email <small>選填；需與 Google 驗證 Email 完全一致</small><input name="google_email" type="email" maxlength="320" autocomplete="off"></label><label>初始密碼<input name="password" type="password" minlength="12" autocomplete="new-password" required></label><button class="primary-button" type="submit">建立帳號</button></form>';
    $rows = '';
    foreach ($users as $account) {
        $active = (int) $account['is_active'] === 1;
        $rows .= '<form method="post" action="' . portal_url('admin_user_update') . '" class="account-edit-form">' . portal_csrf_field() . '<input type="hidden" name="user_id" value="' . (int) $account['user_id'] . '"><div><strong>' . portal_e((string) $account['username']) . '</strong><small>最後登入：' . portal_e(portal_format_datetime($account['last_login_at'] === null ? null : (string) $account['last_login_at'])) . '</small></div><label>顯示名稱<input name="display_name" maxlength="100" value="' . portal_e((string) $account['display_name']) . '" required></label><label>角色<select name="role_code"><option value="reader"' . ((string) $account['role_code'] === 'reader' ? ' selected' : '') . '>閱讀者</option><option value="editor"' . ((string) $account['role_code'] === 'editor' ? ' selected' : '') . '>編輯者</option><option value="admin"' . ((string) $account['role_code'] === 'admin' ? ' selected' : '') . '>管理員</option></select></label><label>Google 登入 Email<input name="google_email" type="email" maxlength="320" autocomplete="off" value="' . portal_e((string) ($account['google_email_normalized'] ?? '')) . '"><small>' . (($account['google_subject'] ?? null) === null ? '尚未完成首次 Google 驗證' : '已綁定 Google 身分') . '</small></label><label class="checkbox-label"><input type="checkbox" name="is_active" value="1"' . ($active ? ' checked' : '') . '>啟用</label><label>重設密碼 <small>留白不變更</small><input name="new_password" type="password" minlength="12" autocomplete="new-password"></label><button class="secondary-button compact-button" type="submit">儲存</button></form>';
    }
    return '<section class="account-management"><div class="panel admin-section"><div class="section-heading"><div><p class="eyebrow">SYSTEM ACCOUNTS</p><h2>系統帳號</h2><p>只有管理者可以建立、停用或重設帳號；密碼不會儲存在稽核紀錄中。</p></div><span class="count-badge">' . count($users) . ' 位</span></div><div class="account-list">' . $rows . '</div></div>' . $create . '</section>';
}

function portal_render_document_editor(PDO $database, array $admin, string $publicId): void
{
    $document = portal_get_catalog_document($database, $admin, $publicId);
    if ($document === null) {
        http_response_code(404);
        portal_render_page('找不到文件', '<section class="panel narrow"><h1>找不到文件</h1><p>請返回管理後台重新選擇。</p><a class="primary-button" href="' . portal_url('admin') . '">返回管理後台</a></section>', $admin);
        return;
    }
    $types = portal_get_document_types($database);
    $phases = portal_get_ssdlc_phases($database);
    $existingRoles = [];
    foreach ($document['phase_roles'] as $role) {
        $existingRoles[(string) $role['phase_code'] . ':' . (string) $role['role_code']] = true;
    }
    $existingTypes = [];
    foreach ((array) ($document['document_types'] ?? []) as $type) {
        $existingTypes[(string) $type['type_code']] = true;
    }
    $typeChecks = '';
    foreach ($types as $type) {
        $code = (string) $type['type_code'];
        $typeChecks .= '<label class="type-check-card"><input type="checkbox" name="type_codes[]" value="' . portal_e($code) . '"' . (isset($existingTypes[$code]) ? ' checked' : '') . '><span><strong>' . portal_e((string) $type['display_name']) . '</strong><small>' . portal_e((string) ($type['description'] ?? '')) . '</small></span></label>';
    }
    $phaseChecks = '';
    foreach ($phases as $phase) {
        $code = (string) $phase['phase_code'];
        $phaseChecks .= '<fieldset class="phase-fieldset"><legend>' . portal_e($code . ' ' . (string) $phase['display_name']) . '</legend><label class="checkbox-label"><input type="checkbox" name="phase_roles[]" value="' . portal_e($code) . ':input"' . (isset($existingRoles[$code . ':input']) ? ' checked' : '') . '> 本階段輸入</label><label class="checkbox-label"><input type="checkbox" name="phase_roles[]" value="' . portal_e($code) . ':output"' . (isset($existingRoles[$code . ':output']) ? ' checked' : '') . '> 本階段產出</label></fieldset>';
    }
    $statuses = '';
    foreach (['draft' => '草稿（僅管理者）', 'published' => '已發布（閱讀者可見）', 'archived' => '封存（僅管理者）'] as $value => $label) {
        $statuses .= '<option value="' . $value . '"' . ((string) $document['status_code'] === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $content = '<a class="back-link" href="' . portal_url('admin') . '">← 返回管理後台</a><section class="panel editor-panel"><p class="eyebrow">DOCUMENT METADATA</p><h1>設定文件中繼資料</h1><p class="muted">實體檔：' . portal_e((string) $document['file_name']) . '（' . portal_e((string) $document['relative_path']) . '）</p><form method="post" action="' . portal_url('admin_document_update') . '" class="admin-form document-editor-form">' . portal_csrf_field() . '<input type="hidden" name="id" value="' . portal_e((string) $document['public_id']) . '"><label>顯示標題<input name="title" maxlength="255" value="' . portal_e((string) $document['title']) . '" required></label><label>發布狀態<select name="status_code">' . $statuses . '</select></label><label class="full-field">摘要<textarea name="summary" maxlength="1000" rows="4" placeholder="供文件庫搜尋與閱讀頁說明使用">' . portal_e((string) ($document['summary'] ?? '')) . '</textarea></label><section class="full-field facet-editor" role="group" aria-labelledby="document-kinds-heading"><div class="facet-heading"><div><p class="eyebrow">DOCUMENT KINDS</p><h2 id="document-kinds-heading">文件種類（可複選）</h2></div><span>內容／用途面向</span></div><p class="muted">勾選這份文件屬於哪些種類；此處不代表開發階段。</p><div class="type-check-grid">' . $typeChecks . '</div></section><section class="full-field facet-editor" role="group" aria-labelledby="ssdlc-stages-heading"><div class="facet-heading"><div><p class="eyebrow">SSDLC STAGES</p><h2 id="ssdlc-stages-heading">開發階段與角色（可複選）</h2></div><span>生命週期面向</span></div><p class="muted">分別勾選文件在哪些階段作為輸入或產出；同一文件可同時是前一階段產出與下一階段輸入。</p><div class="phase-check-grid">' . $phaseChecks . '</div></section><div class="full-field facet-separation-note"><strong>兩個面向獨立保存</strong><span>文件種類不會自動決定開發階段，開發階段也不會限制文件種類；後續可從任一視角檢視同一份文件。</span></div><div class="form-actions"><a class="secondary-button" href="' . portal_url('view', ['id' => (string) $document['public_id']]) . '">閱讀文件</a><button class="primary-button" type="submit">儲存設定</button></div></form></section>';
    portal_render_page('設定文件', $content, $admin);
}

function portal_handle_admin_sync(PDO $database, array $config, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
        $summary = portal_sync_documents($database, $config, $admin);
        $versionFailed = (int) ($summary['version_failed'] ?? 0);
        $versionIncomplete = ($summary['version_capture_enabled'] ?? false) === true && ($summary['version_complete'] ?? false) !== true;
        $versionErrorEntries = array_map(
            static function(array $error): string {
                $code = strtoupper(trim((string) ($error['error_code'] ?? '')));
                if (preg_match('/\A[A-Z0-9_]{3,64}\z/', $code) !== 1) {
                    $code = 'UNKNOWN_ERROR';
                }
                return (string) ((int) ($error['document_id'] ?? 0)) . ':' . $code;
            },
            (array) ($summary['version_errors'] ?? [])
        );
        $versionErrorSuffix = $versionErrorEntries === [] ? '' : '（文件ID:錯誤碼：' . implode('、', $versionErrorEntries) . '）';
        if ($versionIncomplete) {
            $versionErrorSuffix .= '（版本內容尚未全部完成）';
        }
        portal_flash_set(
            ($versionFailed === 0 && !$versionIncomplete) ? 'success' : 'error',
            '重新索引完成：發現 ' . $summary['discovered'] . ' 件、新增 ' . $summary['inserted']
            . ' 件、更新 ' . $summary['updated'] . ' 件、退回草稿 ' . $summary['drafted']
            . ' 件、封存缺檔 ' . $summary['archived'] . ' 件；版本內容新增 '
            . (int) ($summary['version_captured'] ?? 0) . ' 件、已存在 '
            . (int) ($summary['version_already_ready'] ?? 0) . ' 件、失敗 ' . $versionFailed . ' 件。' . $versionErrorSuffix
        );
    } catch (Throwable $exception) {
        error_log('TWWATER admin sync failed: ' . $exception->getMessage());
        portal_flash_set('error', '重新索引失敗；請確認文件來源與系統紀錄。');
    }
    header('Location: ' . portal_url('admin'), true, 303);
}

function portal_handle_admin_bridge_settings(PDO $database, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
        $seconds = portal_bridge_reconcile_interval((string) ($_POST['full_reconcile_seconds'] ?? ''));
        portal_bridge_settings_write(portal_bridge_settings_path(), $seconds);
        try {
            portal_document_audit(
                $database,
                'bridge_settings_update',
                'accepted',
                (int) $admin['user_id'],
                null,
                'full_reconcile_seconds=' . $seconds
            );
        } catch (Throwable $auditException) {
            error_log('TWWATER bridge settings audit failed: ' . $auditException->getMessage());
        }
        portal_flash_set('success', '漏失校正頻率已更新為每 ' . $seconds . ' 秒；橋接器會自動套用，不需重新啟動。');
    } catch (Throwable $exception) {
        error_log('TWWATER bridge settings update failed: ' . $exception->getMessage());
        $message = in_array($exception->getMessage(), [
            '漏失校正秒數必須介於 30 到 3600 秒。',
            '橋接器設定檔無法安全寫入。',
        ], true) ? $exception->getMessage() : '無法儲存漏失校正頻率。';
        portal_flash_set('error', $message);
    }
    header('Location: ' . portal_url('admin'), true, 303);
}

function portal_handle_admin_user_create(PDO $database, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
        portal_create_user($database, $admin, $_POST);
        portal_flash_set('success', '系統帳號已建立。');
    } catch (Throwable $exception) {
        portal_flash_set('error', '無法建立帳號；請確認資料與帳號是否重複。');
    }
    header('Location: ' . portal_url('admin'), true, 303);
}

function portal_handle_admin_user_update(PDO $database, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
        portal_update_user($database, $admin, $_POST);
        portal_flash_set('success', '帳號設定已儲存。');
    } catch (Throwable $exception) {
        portal_flash_set('error', '無法儲存帳號設定；請確認輸入內容與權限。');
    }
    header('Location: ' . portal_url('admin'), true, 303);
}

function portal_handle_admin_document_update(PDO $database, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    $publicId = (string) ($_POST['id'] ?? '');
    try {
        portal_assert_csrf();
        portal_update_document_metadata($database, $admin, $publicId, $_POST);
        portal_flash_set('success', '文件種類與 SSDLC 開發階段／角色已分別儲存。');
        header('Location: ' . portal_url('admin_document', ['id' => $publicId]), true, 303);
        return;
    } catch (Throwable $exception) {
        error_log('TWWATER document update failed: ' . $exception->getMessage());
        $safeMessages = [
            '文件標題或狀態不正確。',
            '文件種類多選資料表或權限尚未完成部署。',
            '發布前必須勾選至少一個文件種類與一個 SSDLC 階段角色。',
            '找不到指定文件。',
        ];
        $message = in_array($exception->getMessage(), $safeMessages, true)
            ? $exception->getMessage()
            : '無法儲存文件設定；請確認資料後再試一次。';
        portal_flash_set('error', $message);
    }
    $redirect = portal_valid_public_id($publicId) === ''
        ? portal_url('admin')
        : portal_url('admin_document', ['id' => $publicId]);
    header('Location: ' . $redirect, true, 303);
}

function portal_handle_admin_documents_bulk_update(PDO $database, array $admin): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    try {
        portal_assert_csrf();
        $updated = portal_bulk_update_document_metadata($database, $admin, $_POST);
        portal_flash_set('success', '批次屬性設定完成：已更新 ' . $updated . ' 份文件。');
    } catch (Throwable $exception) {
        error_log('TWWATER bulk document update failed: ' . $exception->getMessage());
        $safeMessages = [
            '請至少選取一份文件。',
            '一次最多可批次設定 ' . PORTAL_DOCUMENT_LIST_LIMIT . ' 份文件。',
            '批次發布狀態不正確。',
            '批次套用方式不正確。',
            '請至少選擇一項要套用的批次屬性。',
            '請先勾選至少一個要套用的文件種類。',
            '請先勾選至少一個要套用的 SSDLC 階段角色。',
            '部分文件已不存在，請重新整理後再試。',
            '發布前每份文件都必須至少有一個文件種類與一個 SSDLC 階段角色。',
            '文件種類多選資料表或權限尚未完成部署。',
        ];
        $message = in_array($exception->getMessage(), $safeMessages, true)
            ? $exception->getMessage()
            : '無法完成批次設定；所有變更均已取消，請確認資料後再試。';
        portal_flash_set('error', $message);
    }
    header('Location: ' . portal_url('admin'), true, 303);
}
