<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/image_library.php';
function lifecycle_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function lifecycle_insert(PDO $db,int $documentId,string $hash,string $status,int $revision):string{
    $sql="INSERT INTO dbo.image_requirement_revisions(document_id,source_content_hash,revision_number,status_code,completed_by_user_id,completed_at) OUTPUT CONVERT(varchar(36),inserted.public_id) VALUES(?,?,?,?,?,?)";
    $statement=$db->prepare($sql);$completed=$status==='completed';$statement->execute([$documentId,$hash,$revision,$status,$completed?1:null,$completed?gmdate('Y-m-d H:i:s'):null]);return strtolower((string)$statement->fetchColumn());
}
$config=portal_config(false);$config['document_root']=dirname(__DIR__).'/document-library';
$db=portal_open_database($config);$documentPath=dirname(__DIR__).'/document-library/infographic/third-party-login/infographic.png';
$currentHash=strtolower((string)hash_file('sha256',$documentPath));$historicalHash=str_repeat($currentHash[0]==='f'?'e':'f',64);$admin=['user_id'=>1,'role_code'=>'admin'];
$existing=(int)$db->query("SELECT CASE WHEN OBJECT_ID(N'dbo.documents',N'U') IS NULL AND OBJECT_ID(N'dbo.image_requirement_revisions',N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();
lifecycle_assert($existing===0,'isolated handler test refuses to run when production-like tables already exist');
$db->beginTransaction();
try{
    $db->exec("CREATE TABLE dbo.documents(document_id BIGINT NOT NULL PRIMARY KEY,public_id UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),relative_path NVARCHAR(1000) NOT NULL,extension VARCHAR(16) NOT NULL,content_hash CHAR(64) NOT NULL,status_code VARCHAR(32) NOT NULL)");
    $db->exec("CREATE TABLE dbo.image_requirement_revisions(image_requirement_revision_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,public_id UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),document_id BIGINT NOT NULL,source_content_hash CHAR(64) NOT NULL,revision_number INT NOT NULL,status_code VARCHAR(32) NOT NULL,completed_by_user_id BIGINT NULL,completed_at DATETIME2 NULL,archived_by_user_id BIGINT NULL,archived_at DATETIME2 NULL)");
    $insertDocument=$db->prepare("INSERT INTO dbo.documents(document_id,relative_path,extension,content_hash,status_code) VALUES(1,?,?,?,'active')");$insertDocument->execute(['infographic/third-party-login/infographic.png','png',$currentHash]);
    $historicalReady=lifecycle_insert($db,1,$historicalHash,'ready',1);$currentReady=lifecycle_insert($db,1,$currentHash,'ready',2);$currentCompleted=lifecycle_insert($db,1,$currentHash,'completed',3);$historicalCompleted=lifecycle_insert($db,1,$historicalHash,'completed',4);
    $rejected=false;try{portal_image_requirement_revision_set_status($db,$config,$admin,$historicalReady);}catch(RuntimeException $e){$rejected=$e->getMessage()==='CONFLICT';}lifecycle_assert($rejected,'historical-source ready Revision was completed');
    $readyState=$db->prepare("SELECT status_code FROM dbo.image_requirement_revisions WHERE public_id=CONVERT(uniqueidentifier,?)");$readyState->execute([$historicalReady]);lifecycle_assert($readyState->fetchColumn()==='ready','rejected historical Revision was mutated');
    $completed=portal_image_requirement_revision_set_status($db,$config,$admin,$currentReady);lifecycle_assert($completed['status_code']==='completed','current-source ready Revision did not complete');
    $rejected=false;try{portal_image_requirement_revision_set_archived($db,$config,$admin,$currentCompleted,false);}catch(RuntimeException $e){$rejected=$e->getMessage()==='CONFLICT';}lifecycle_assert($rejected,'current-source completed owner was archived');
    $ownerState=$db->prepare("SELECT archived_at FROM dbo.image_requirement_revisions WHERE public_id=CONVERT(uniqueidentifier,?)");$ownerState->execute([$currentCompleted]);lifecycle_assert($ownerState->fetchColumn()===null,'rejected completed owner was mutated');
    $archived=portal_image_requirement_revision_set_archived($db,$config,$admin,$historicalCompleted,false);lifecycle_assert($archived['archived']===true,'historical completed Revision did not archive');
    lifecycle_assert($db->inTransaction(),'handler committed the caller-owned integration transaction');
} finally {if($db->inTransaction())$db->rollBack();}
$residue=(int)$db->query("SELECT CASE WHEN OBJECT_ID(N'dbo.documents',N'U') IS NULL AND OBJECT_ID(N'dbo.image_requirement_revisions',N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn();lifecycle_assert($residue===0,'handler integration test left schema residue');
echo "[OK] Direct server lifecycle guards reject historical completion/current-owner archive and leave zero residue.\n";
