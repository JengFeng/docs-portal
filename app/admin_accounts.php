<?php
declare(strict_types=1);

function portal_list_admin_accounts(PDO $database): array
{
    return $database->query('SELECT user_id, username, display_name, role_code, is_enabled, login_mode, google_email, google_bound_at, failed_login_count, locked_until, last_login_at, created_at, updated_at FROM auth_users ORDER BY is_enabled DESC, role_code DESC, username ASC')->fetchAll();
}
function portal_admin_login_mode_label(string $mode): string { return match($mode){'local_only'=>'僅本機帳密','google_only'=>'僅預綁 Google 登入','local_and_google'=>'本機帳密與 Google 皆可',default=>'未知登入方式'}; }
function portal_admin_google_binding_label(array $account): string { if(trim((string)($account['google_email']??''))==='')return '未設定';return ((string)($account['google_bound_at']??''))===''?'等待首次 Google 驗證':'已固定 Google 身分'; }
