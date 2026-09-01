<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
    $config=portal_config();$database=portal_open_database($config);
    if(!portal_document_version_blob_schema_ready($database)){throw new RuntimeException('Migration 025 is not active.');}
    $result=portal_capture_current_document_version_contents($database,$config,null);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    exit((int)($result['failed']??0)===0?0:2);
}catch(Throwable $exception){fwrite(STDERR,"Document version content backfill failed: ".$exception->getMessage().PHP_EOL);exit(1);}
