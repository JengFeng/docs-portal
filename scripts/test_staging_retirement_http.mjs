import {spawn} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
const php='C:/PHP/8.5.9/php.exe';
const runtime='C:/TWWATER/runtime/pre-upload-previews';
const root=fs.mkdtempSync(path.join(runtime,'staging-retirement-http-'));
const marker=path.join(root,'legacy-handler-ran.txt');
const port=9477+Math.floor(Math.random()*100);
const fixture=path.join(root,'index.php');
fs.writeFileSync(fixture,`<?php
declare(strict_types=1);
require 'C:/web/gary/TWWATER/app/staging_retirement.php';
$action=(string)($_GET['action']??'');
$response=portal_disabled_staging_response($action);
if($response===null){file_put_contents(${JSON.stringify(marker)},'unexpected');http_response_code(500);echo 'legacy';exit;}
http_response_code((int)$response['status']);
header('Content-Type: '.(string)$response['content_type']);
if($response['kind']==='json'){echo json_encode(['ok'=>false,'error'=>$response['body']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}else{echo '<!doctype html><meta charset="utf-8"><h1>'.htmlspecialchars((string)$response['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h1><p>'.htmlspecialchars((string)$response['body'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';}
`,'utf8');
const child=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{stdio:'ignore'});
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const routes=['staging','staging_workspace','staging_chunk','staging_finalize','staging_preview_asset','staging_confirm','staging_return'];
async function request(route,method){for(let i=0;i<40;i++){try{return await fetch(`http://127.0.0.1:${port}/?action=${route}`,{method});}catch{}await sleep(100);}throw new Error('local PHP dispatcher unavailable');}
async function main(){
  const results=[];
  for(const route of routes){
    for(const method of ['GET','POST']){
      const response=await request(route,method);
      const body=await response.text();
      const type=response.headers.get('content-type')||'';
      const expectedType=route==='staging'?'text/html':'application/json';
      const ok=response.status===410&&type.includes(expectedType)&&body.includes('Google Drive');
      results.push({route,method,status:response.status,type,ok});
      if(!ok)throw new Error(`retirement response mismatch: ${route} ${method}`);
    }
  }
  if(fs.existsSync(marker))throw new Error('legacy handler sentinel was written');
  console.log(JSON.stringify({ok:true,request_count:results.length,routes:routes.length,legacy_side_effect:false},null,2));
}
async function cleanup(){if(child.exitCode===null){child.kill();await Promise.race([new Promise(r=>child.once('exit',r)),sleep(2000)])}fs.rmSync(root,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
main().then(cleanup).catch(async error=>{console.error(error.stack||error);await cleanup();process.exitCode=1});
