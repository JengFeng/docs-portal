<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('此工具只能由本機命令列執行。');
}

$state = portal_state_binding_read(portal_state_binding_path());
$bindingPath = portal_document_library_binding_config_path();
printf("STATE_BINDING=OK\n");
if ($bindingPath === '') {
    printf("DOCUMENT_BINDING=unavailable_in_cli\n");
} else {
    $documentBinding = portal_document_library_binding_read($bindingPath);
    printf("DOCUMENT_BINDING=OK\n");
    printf("DOCUMENT_ROOT_READABLE=%s\n", is_readable((string) $documentBinding['document_root']) ? 'yes' : 'no');
}
printf("STATE_DATABASE=%s\n", is_file((string) $state['sqlite_path']) ? 'present' : 'absent');

if (!is_file((string) $state['sqlite_path'])) {
    printf("FIRST_ADMIN_REQUIRED\n");
    exit(0);
}

$database = portal_open_state_database();
portal_state_verify_audit_chain($database);
printf("SQLite audit chain=OK\n");
printf("FIRST_ADMIN_REQUIRED=%s\n", ((int) $database->query('SELECT COUNT(*) FROM auth_users')->fetchColumn()) === 0 ? 'yes' : 'no');
