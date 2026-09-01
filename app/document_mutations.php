<?php
declare(strict_types=1);

const PORTAL_DOCUMENT_REPLACEMENT_MAX_BYTES = 524288000;
const PORTAL_DOCUMENT_REPLACEMENT_OUTPUT_MAX_BYTES = 8192;
const PORTAL_DOCUMENT_MUTATION_RECOVERY_LIMIT = 10;
const PORTAL_DOCUMENT_MUTATION_FIXED_ROOT = 'C:\\web\\gary\\TWWATER\\document-library';

function portal_assert_document_mutation_root(array $config): void
{
    $configured=realpath((string)($config['document_root']??''));
    $fixed=realpath(PORTAL_DOCUMENT_MUTATION_FIXED_ROOT);
    if ($configured===false || $fixed===false || !is_dir($fixed) || is_link($fixed) || strcasecmp(rtrim($configured,'\\/'),rtrim($fixed,'\\/'))!==0) {
        throw new RuntimeException('文件替換根目錄與固定安全工具不一致。');
    }
}

function portal_document_mutation_schema_available(PDO $database): bool
{
    try {
        return (int)$database->query("SELECT CASE WHEN
            OBJECT_ID(N'dbo.document_mutation_operations',N'U') IS NOT NULL
            AND OBJECT_ID(N'dbo.document_mutation_events',N'U') IS NOT NULL
            AND COL_LENGTH(N'dbo.documents',N'archive_previous_status_code') IS NOT NULL
            AND COL_LENGTH(N'dbo.documents',N'archived_by_user_id') IS NOT NULL
            AND COL_LENGTH(N'dbo.documents',N'archived_at') IS NOT NULL
            AND COL_LENGTH(N'dbo.documents',N'archive_reason') IS NOT NULL
            AND EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.document_mutation_operations') AND name=N'UX_document_mutation_operations_active' AND is_unique=1 AND has_filter=1 AND is_disabled=0)
            AND (SELECT COUNT(*) FROM sys.objects WHERE object_id IN(OBJECT_ID(N'dbo.portal_prepare_document_replacement'),OBJECT_ID(N'dbo.portal_mark_document_replaced'),OBJECT_ID(N'dbo.portal_complete_document_replacement'),OBJECT_ID(N'dbo.portal_fail_document_replacement'),OBJECT_ID(N'dbo.portal_list_active_document_mutations'),OBJECT_ID(N'dbo.portal_soft_archive_document'),OBJECT_ID(N'dbo.portal_restore_archived_document')) AND type=N'P')=7
            AND EXISTS(SELECT 1 FROM dbo.schema_migrations WHERE script_name=N'029_secure_document_mutations.sql')
            THEN 1 ELSE 0 END")->fetchColumn() === 1;
    } catch (Throwable) { return false; }
}

function portal_validate_document_replacement_upload(array $document, array $upload, bool $requireHttpUpload = true): array
{
    $expectedName = (string)($document['file_name'] ?? '');
    $expectedExtension = strtolower((string)($document['extension'] ?? ''));
    $name = (string)($upload['name'] ?? '');
    $temporary = (string)($upload['tmp_name'] ?? '');
    $size = filter_var($upload['size'] ?? null, FILTER_VALIDATE_INT);
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $expectedName === '' || $name !== $expectedName) {
        throw new RuntimeException('替換檔案必須保留完全相同的檔名。');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === '' || !hash_equals($expectedExtension, $extension)) {
        throw new RuntimeException('替換檔案必須保留相同副檔名。');
    }
    if ($size === false || $size < 1 || $size > PORTAL_DOCUMENT_REPLACEMENT_MAX_BYTES) {
        throw new RuntimeException('替換檔案不得超過 500 MiB。');
    }
    if ($requireHttpUpload && ($temporary === '' || !is_uploaded_file($temporary))) {
        throw new RuntimeException('替換來源不是有效的 HTTP 上傳。');
    }
    return ['name'=>$name,'tmp_name'=>$temporary,'size'=>(int)$size,'extension'=>$extension];
}

function portal_document_mutation_operation_id(string $value): string
{
    return portal_valid_public_id($value);
}

function portal_prepare_document_replacement(PDO $database, array $document, array $actor, string $candidateHash, int $candidateSize): string
{
    $operationId = portal_document_version_uuid();
    $statement = $database->prepare('EXEC dbo.portal_prepare_document_replacement @operation_id=?,@document_id=?,@expected_hash=?,@candidate_hash=?,@candidate_size=?,@requested_by_user_id=?');
    $statement->execute([$operationId,(int)$document['document_id'],(string)$document['content_hash'],$candidateHash,$candidateSize,(int)$actor['user_id']]);
    $result = $statement->fetch();
    while ($statement->nextRowset()) {}
    if (!is_array($result) || portal_document_mutation_operation_id((string)($result['operation_id'] ?? '')) !== $operationId) {
        throw new RuntimeException('無法建立安全替換意圖。');
    }
    return $operationId;
}

function portal_stage_replacement_candidate(array $config, array $document, array $upload, string $operationId): array
{
    $operationId = portal_document_mutation_operation_id($operationId);
    if ($operationId === '') throw new RuntimeException('替換操作識別碼無效。');
    $target = portal_document_file_path($config,$document);
    $directory = dirname($target);
    $extension = strtolower((string)$document['extension']);
    $candidateName = '.' . $operationId . '.replace.' . $extension;
    $candidatePath = $directory . DIRECTORY_SEPARATOR . $candidateName;
    $input = fopen((string)$upload['tmp_name'],'rb');
    $output = @fopen($candidatePath,'x+b');
    if (!is_resource($input) || !is_resource($output) || is_link($candidatePath)) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        @unlink($candidatePath);
        throw new RuntimeException('無法建立同目錄安全候選檔。');
    }
    $hash = hash_init('sha256'); $written = 0;
    try {
        while (!feof($input)) {
            $chunk=fread($input,1048576);
            if ($chunk===false) throw new RuntimeException('讀取上傳內容失敗。');
            if ($chunk==='') continue;
            $length=strlen($chunk); $written += $length;
            if ($written > PORTAL_DOCUMENT_REPLACEMENT_MAX_BYTES || fwrite($output,$chunk)!==$length) throw new RuntimeException('寫入候選檔失敗。');
            hash_update($hash,$chunk);
        }
        if (!fflush($output) || $written !== (int)$upload['size']) throw new RuntimeException('候選檔大小驗證失敗。');
        $candidateHash=hash_final($hash);
        if (!rewind($output) || !hash_equals($candidateHash,(string)hash_file('sha256',$candidatePath))) throw new RuntimeException('候選檔回讀驗證失敗。');
        return ['name'=>$candidateName,'path'=>$candidatePath,'hash'=>$candidateHash,'size'=>$written];
    } catch (Throwable $exception) {
        @unlink($candidatePath); throw $exception;
    } finally { fclose($input); fclose($output); }
}

function portal_run_atomic_document_replace(array $document, array $candidate): array
{
    $helper = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'atomic-replace-document.ps1';
    $command=['C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe','-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','RemoteSigned','-File',$helper,
        '-RelativePath',(string)$document['relative_path'],'-CandidateName',(string)$candidate['name'],'-ExpectedHash',(string)$document['content_hash'],'-CandidateHash',(string)$candidate['hash']];
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'blocking_pipes'=>true]);
    if (!is_resource($process)) throw new RuntimeException('無法啟動固定原子替換工具。');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1],PORTAL_DOCUMENT_REPLACEMENT_OUTPUT_MAX_BYTES+1);
    $stderr=stream_get_contents($pipes[2],PORTAL_DOCUMENT_REPLACEMENT_OUTPUT_MAX_BYTES+1);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    if (!is_string($stdout) || strlen($stdout)>PORTAL_DOCUMENT_REPLACEMENT_OUTPUT_MAX_BYTES || !is_string($stderr) || strlen($stderr)>PORTAL_DOCUMENT_REPLACEMENT_OUTPUT_MAX_BYTES) throw new RuntimeException('原子替換工具輸出超過限制。');
    try { $result=json_decode(trim($stdout),true,8,JSON_THROW_ON_ERROR); } catch(Throwable) { throw new RuntimeException('原子替換工具回應無效。'); }
    if ($exit!==0 || !is_array($result) || ($result['ok']??false)!==true || !hash_equals((string)$candidate['hash'],strtolower((string)($result['actual_hash']??'')))) throw new RuntimeException('原子替換未完成安全驗證。');
    return $result;
}

function portal_signal_document_bridge(array $config, string $operationId): void
{
    $path=(string)($config['bridge_signal_path']??'');
    $operationId=portal_document_mutation_operation_id($operationId);
    if ($path==='' || $operationId==='') throw new RuntimeException('橋接訊號路徑無效。');
    $directory=dirname($path); $temporary=$directory.DIRECTORY_SEPARATOR.'.document-bridge-'.$operationId.'.tmp';
    $value='portal-replace-'.$operationId;
    if (file_put_contents($temporary,$value,LOCK_EX)!==strlen($value) || !rename($temporary,$path)) { @unlink($temporary); throw new RuntimeException('無法建立文件橋接訊號。'); }
}

function portal_fail_document_replacement(PDO $database, string $operationId, string $errorCode): void
{
    $statement=$database->prepare('EXEC dbo.portal_fail_document_replacement @operation_id=?,@error_code=?');
    $statement->execute([$operationId,$errorCode]); while($statement->nextRowset()){}
}

function portal_execute_document_replacement(PDO $database, array $config, array $document, array $actor, array $upload): string
{
    portal_assert_document_mutation_root($config);
    $upload=portal_validate_document_replacement_upload($document,$upload,true);
    $uploadHash=(string)hash_file('sha256',$upload['tmp_name']);
    if (!preg_match('/\A[0-9a-f]{64}\z/',$uploadHash)) throw new RuntimeException('無法驗證上傳雜湊。');
    if (hash_equals((string)$document['content_hash'],$uploadHash)) throw new RuntimeException('上傳內容與目前版本相同。');
    $operationId=portal_prepare_document_replacement($database,$document,$actor,$uploadHash,(int)$upload['size']);
    $candidate=null; $mutationAttempted=false;
    try {
        $candidate=portal_stage_replacement_candidate($config,$document,$upload,$operationId);
        if (!hash_equals($uploadHash,(string)$candidate['hash'])) throw new RuntimeException('候選內容與上傳內容不一致。');
        $mutationAttempted=true;
        $result=portal_run_atomic_document_replace($document,$candidate);
        $mark=$database->prepare('EXEC dbo.portal_mark_document_replaced @operation_id=?,@actual_hash=?,@actual_size=?,@source_modified_utc=?');
        $mark->execute([$operationId,$uploadHash,(int)$upload['size'],(string)$result['modified_utc']]); while($mark->nextRowset()){}
        portal_signal_document_bridge($config,$operationId);
        $summary=portal_sync_documents($database,$config,$actor);
        if (($summary['version_capture_enabled']??false)!==true || ($summary['version_complete']??false)!==true) throw new RuntimeException('不可變 SQL 版本尚未完成。');
        $complete=$database->prepare('EXEC dbo.portal_complete_document_replacement @operation_id=?,@actual_hash=?');
        $complete->execute([$operationId,$uploadHash]); while($complete->nextRowset()){}
        return $operationId;
    } catch(Throwable $exception) {
        if (!$mutationAttempted) {
            try { portal_fail_document_replacement($database,$operationId,'PREPARE_FAILED'); } catch(Throwable) {}
        }
        // Once File.Replace may have run, retain the active SQL intent. Startup
        // reconciliation compares live bytes with both hashes before transitioning.
        throw $exception;
    } finally {
        if (!$mutationAttempted && is_array($candidate) && is_file((string)$candidate['path'])) @unlink((string)$candidate['path']);
    }
}

function portal_reconcile_document_mutations(PDO $database, array $config, ?array $actor=null): void
{
    if (!portal_document_mutation_schema_available($database)) return;
    try { portal_assert_document_mutation_root($config); } catch(Throwable) { return; }
    try {
        $rows=$database->query("EXEC dbo.portal_list_active_document_mutations @limit=".PORTAL_DOCUMENT_MUTATION_RECOVERY_LIMIT)->fetchAll();
        foreach($rows as $row) {
            if ((string)$row['status_code']==='prepared') {
                try {
                    $path=portal_document_file_path($config,$row);
                    $size=filesize($path); $liveHash=$size===false || $size>PORTAL_DOCUMENT_REPLACEMENT_MAX_BYTES ? '' : strtolower((string)hash_file('sha256',$path));
                    if ($liveHash!=='' && hash_equals((string)$row['candidate_hash'],$liveHash) && (int)$size===(int)$row['candidate_size']) {
                        $mark=$database->prepare('EXEC dbo.portal_mark_document_replaced @operation_id=?,@actual_hash=?,@actual_size=?,@source_modified_utc=?');
                        $modified=filemtime($path); if($modified===false) throw new RuntimeException('mtime unavailable');
                        $mark->execute([(string)$row['operation_id'],$liveHash,(int)$size,gmdate('Y-m-d\\TH:i:s\\Z',$modified)]); while($mark->nextRowset()){}
                        $row['status_code']='replaced';
                    } elseif ($liveHash!=='' && hash_equals((string)$row['expected_hash'],$liveHash)) {
                        $candidate=dirname($path).DIRECTORY_SEPARATOR.'.'.(string)$row['operation_id'].'.replace.'.strtolower((string)$row['extension']);
                        $requestedAt=strtotime((string)($row['requested_at']??'').' UTC');
                        $stillFresh=$requestedAt!==false && $requestedAt > time()-600;
                        if ($stillFresh) {
                            continue;
                        }
                        if (is_file($candidate) && !is_link($candidate)) {
                            @unlink($candidate);
                        }
                        portal_fail_document_replacement($database,(string)$row['operation_id'],'NOT_APPLIED');
                        continue;
                    } else {
                        continue; // Publication is ambiguous; retain the active intent and fail closed.
                    }
                } catch(Throwable) { continue; }
            }
            if ((string)$row['status_code']==='replaced') {
                portal_signal_document_bridge($config,(string)$row['operation_id']);
                $summary=portal_sync_documents($database,$config,$actor);
                if (($summary['version_complete']??false)===true) {
                    $statement=$database->prepare('EXEC dbo.portal_complete_document_replacement @operation_id=?,@actual_hash=?');
                    $statement->execute([(string)$row['operation_id'],(string)$row['candidate_hash']]); while($statement->nextRowset()){}
                }
            }
        }
    } catch(Throwable $exception) { error_log('TWWATER mutation reconciliation deferred: '.$exception->getMessage()); }
}

function portal_render_document_mutation_actions(array $document, array $user): string
{
    $id=portal_e((string)$document['public_id']); $hash=portal_e((string)$document['content_hash']);
    $view=portal_e(portal_url('view',['id'=>(string)$document['public_id']]));
    $edit=portal_e(portal_url('document_replace',['id'=>(string)$document['public_id']]));
    return '<div class="document-row-actions"><a class="secondary-button compact-button" href="'.$view.'">檢視</a><a class="primary-button compact-button" href="'.$edit.'">編輯</a>'
        .'<form method="post" action="'.portal_e(portal_url('document_archive')).'" data-confirm="確定封存這份文件嗎？實體檔案與全部版本都會保留。">'.portal_csrf_field().'<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="expected_hash" value="'.$hash.'"><button class="danger-button compact-button" type="submit">封存</button></form></div>';
}

function portal_soft_archive_document(PDO $database, array $document, array $actor, string $expectedHash): void
{
    if (!hash_equals((string)$document['content_hash'],portal_document_version_hash($expectedHash))) throw new RuntimeException('文件已變更，請重新整理。');
    $statement=$database->prepare('EXEC dbo.portal_soft_archive_document @document_id=?,@expected_hash=?,@actor_user_id=?');
    $statement->execute([(int)$document['document_id'],$expectedHash,(int)$actor['user_id']]); while($statement->nextRowset()){}
}

function portal_restore_archived_document(PDO $database, int $documentId, array $actor): void
{
    $statement=$database->prepare('EXEC dbo.portal_restore_archived_document @document_id=?,@actor_user_id=?');
    $statement->execute([$documentId,(int)$actor['user_id']]); while($statement->nextRowset()){}
}
