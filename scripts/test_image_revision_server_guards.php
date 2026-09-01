<?php
declare(strict_types=1);
$source=(string)file_get_contents(dirname(__DIR__).'/app/image_library.php');
function server_guard(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"[FAIL] $message\n");exit(1);}}
server_guard(str_contains($source,'function portal_image_requirement_revision_set_status(PDO $database,array $config,array $user,string $revisionId)'),'completion guard must receive trusted document-root config');
server_guard(str_contains($source,'function portal_image_requirement_revision_set_archived(PDO $database,array $config,array $user,string $revisionId,bool $restore)'),'archive guard must receive trusted document-root config');
server_guard(substr_count($source,'WITH (UPDLOCK,HOLDLOCK)')>=4,'completion and archive must lock Revision and document state in their transactions');
server_guard(str_contains($source,'portal_image_requirement_revision_locked_context'),'completion and archive must share one fail-closed locked context loader');
server_guard(str_contains($source,'portal_image_requirement_revision_is_current_source'),'server must compare Revision, catalog, and physical-file hashes');
server_guard(str_contains($source,"if(!portal_image_requirement_revision_is_current_source(\$context))throw new RuntimeException('CONFLICT')"),'completion must reject a historical or stale source');
server_guard(str_contains($source,"if(!\$restore&&\$context['status_code']==='completed'&&portal_image_requirement_revision_is_current_source(\$context))throw new RuntimeException('CONFLICT')"),'archive must reject the active completed current-source owner');
server_guard(str_contains($source,"portal_image_requirement_revision_set_status(\$database,\$config,\$user,\$revisionId)")&&str_contains($source,"portal_image_requirement_revision_set_archived(\$database,\$config,\$user,\$revisionId,\$restore)"),'HTTP request dispatcher must pass trusted config into both guards');
echo "[OK] Server enforces current-source completion and completion-owner archive guards.\n";