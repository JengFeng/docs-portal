<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function editor_permissions_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reader = ['user_id' => 1, 'role_code' => 'reader'];
$editor = ['user_id' => 2, 'role_code' => 'editor'];
$otherEditor = ['user_id' => 3, 'role_code' => 'editor'];
$admin = ['user_id' => 4, 'role_code' => 'admin'];

editor_permissions_assert(!portal_can_edit_documents($reader), 'Reader must not edit documents.');
editor_permissions_assert(portal_can_edit_documents($editor), 'Editor must edit documents.');
editor_permissions_assert(portal_can_edit_documents($admin), 'Admin must edit documents.');
editor_permissions_assert(!portal_can_confirm_staged_revision($reader, 1), 'Reader must not confirm revisions.');
editor_permissions_assert(portal_can_confirm_staged_revision($editor, 2), 'Editor must confirm own revision.');
editor_permissions_assert(!portal_can_confirm_staged_revision($otherEditor, 2), 'Editor must not confirm another editor revision.');
editor_permissions_assert(portal_can_confirm_staged_revision($admin, 2), 'Admin must confirm any revision.');

$bootstrap = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php');
editor_permissions_assert(str_contains($bootstrap, "['reader', 'editor', 'admin']"), 'Account role allowlist must include editor.');
editor_permissions_assert(substr_count($bootstrap, 'if (!portal_can_edit_documents($user))') >= 2, 'Editor must be allowed to list and open draft documents.');

$presentation = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'presentation.php');
editor_permissions_assert(str_contains($presentation, "'annotate' => portal_can_edit_documents(\$user)"), 'Manifest must grant annotate to editors.');
editor_permissions_assert(str_contains($presentation, "'create_request' => portal_can_edit_documents(\$user)"), 'Manifest must grant change requests to editors.');
editor_permissions_assert(substr_count($presentation, '!portal_can_edit_documents($user)') >= 2, 'Mutation handlers must enforce editor permission server-side.');
editor_permissions_assert(preg_match('/if \(!portal_can_edit_documents\(\$user\)\) \{\s+\$sql \.= " AND d\.status_code = \'published\'";/s', $presentation) === 1, 'Editor change requests must remain accessible for draft documents.');

$index = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php');
editor_permissions_assert(str_contains($index, '<option value="editor">編輯者</option>'), 'Admin account UI must offer editor role.');

$migration = (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '009_editor_role.sql');
editor_permissions_assert(str_contains($migration, "role_code IN ('reader', 'editor', 'admin')"), 'Migration must extend the SQL role constraint.');

echo "[OK] Editor permission contracts passed.\n";
