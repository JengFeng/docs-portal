<?php
declare(strict_types=1);
function worker_install_assert(bool $v,string $m):void{if(!$v){fwrite(STDERR,"[FAIL] {$m}\n");exit(1);}}
$root=dirname(__DIR__);$path=$root.'/scripts/install_site_feedback_worker.ps1';worker_install_assert(is_file($path),'worker installer must exist');$source=(string)file_get_contents($path);
foreach(['site-feedback','TWWATER Site Feedback Worker','New-ScheduledTaskAction','Register-ScheduledTask','DoNotStartTask','ReadAndExecute','Modify','IIS AppPool\\TWWATER_PortalPool','ProtectedData','PORTAL_RATE_KEY','worker-key.dpapi','--key-file'] as $token)worker_install_assert(str_contains($source,$token),'installer contract missing: '.$token);
worker_install_assert(str_contains($source,'Add-Type -AssemblyName System.Security')&&strpos($source,'Add-Type -AssemblyName System.Security')<strpos($source,'[Security.Cryptography.ProtectedData]::Protect'),'installer must load the ProtectedData assembly before DPAPI key creation');
worker_install_assert(str_contains($source,'Set-ProtectedAcl $siteRoot'),'site-feedback queue root must have a protected ACL before child queues are created');
worker_install_assert(str_contains($source,"'worker-tmp'")&&str_contains($source,'Set-ProtectedAcl $workerTemp $common'),'Hermes prompts must use a worker-only protected temporary directory');
worker_install_assert(str_contains($source,"@('analysis')")&&str_contains($source,"\$kind+'-requests'")&&str_contains($source,"\$kind+'-results'")&&!str_contains($source,"@('analysis','execution')"),'installer must create analysis-only queues; execution requires Discord second-channel confirmation');
worker_install_assert(!str_contains($source,'--yolo'),'unattended worker must never bypass Hermes approvals');
worker_install_assert(str_contains($source,'-ExecutionTimeLimit')&&str_contains($source,'IgnoreNew'),'worker task must be bounded and singleton');
echo "[OK] site feedback worker installer ACL, queue, bounded task, and no-yolo contracts passed.\n";
