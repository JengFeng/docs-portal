<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'document_library_binding.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'portal_state.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sqlite_accounts.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sqlite_auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'google_auth.php';

const PORTAL_IDLE_TIMEOUT_SECONDS = 1800;
const PORTAL_ABSOLUTE_TIMEOUT_SECONDS = 28800;
const PORTAL_DOCUMENT_LIST_LIMIT = 500;

function portal_path_has_reparse_component(string $path): bool
{
    $normalized = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/');
    if (preg_match('/\A([A-Za-z]:)[\\\\](.*)\z/', $normalized, $matches) !== 1) return true;
    $current = strtoupper($matches[1]) . DIRECTORY_SEPARATOR;
    foreach (preg_split('/[\\\\]+/', $matches[2], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
        $current = rtrim($current, '\\/') . DIRECTORY_SEPARATOR . $segment;
        if ((!file_exists($current) && !is_link($current)) || is_link($current)) return true;
    }
    return false;
}

function portal_direct_sync_root_is_allowed(string $configuredPath, ?string $expectedRoot = null): bool
{
    if ($configuredPath === '' || str_contains($configuredPath, "\0") || portal_path_has_reparse_component($configuredPath)) return false;
    $candidate = realpath($configuredPath);
    if ($candidate === false || !is_dir($candidate) || is_link($candidate)) return false;
    $key = static fn(string $path): string => strtolower(rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/'));
    if ($key($configuredPath) !== $key($candidate)) return false;
    if ($expectedRoot === null) return true;
    $expected = realpath($expectedRoot);
    return $expected !== false && !is_link($expected) && $key($expectedRoot) === $key($expected) && $key($candidate) === $key($expected);
}

function portal_google_login_configured(array $config): bool
{
    return (bool) ($config['google_login_enabled'] ?? false)
        && preg_match('/\A[0-9A-Za-z._-]+\.apps\.googleusercontent\.com\z/', (string) ($config['google_oauth_client_id'] ?? '')) === 1
        && (string) ($config['google_oauth_client_secret'] ?? '') !== ''
        && filter_var((string) ($config['google_oauth_redirect_uri'] ?? ''), FILTER_VALIDATE_URL) !== false
        && str_starts_with((string) ($config['google_oauth_redirect_uri'] ?? ''), 'https://')
        && is_file((string) ($config['google_ca_bundle'] ?? ''))
        && is_readable((string) ($config['google_ca_bundle'] ?? ''));
}

function portal_config(bool $requireDocumentRoot = true): array
{
    $session = trim((string) (getenv('PORTAL_SESSION_SAVE_PATH') ?: ''));
    $bindingPath = portal_document_library_binding_config_path();
    $missing = [];
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $missing[] = 'pdo_sqlite';
    if ($session === '' || !is_dir($session) || !is_writable($session) || portal_path_has_reparse_component($session)) $missing[] = 'PORTAL_SESSION_SAVE_PATH';
    $documentRoot = '';
    if ($requireDocumentRoot) {
        try { $documentRoot = portal_document_library_binding_read($bindingPath)['document_root']; }
        catch (Throwable) { $missing[] = 'PORTAL_DOCUMENT_BINDING_CONFIG_PATH'; }
    }
    try { $state = portal_state_binding_read(portal_state_binding_path()); }
    catch (Throwable) { $state = null; $missing[] = 'portal-state-binding.json'; }
    $cookiePath = trim((string) (getenv('PORTAL_COOKIE_PATH') ?: ''));
    if (preg_match('#\A/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/\z#', $cookiePath) !== 1) $cookiePath = '/gary/TWWATER/';
    $config = [
        'ready' => $missing === [], 'missing' => $missing, 'session_save_path' => $session, 'cookie_path' => $cookiePath,
        'document_root' => $documentRoot, 'document_binding_config_path' => $bindingPath,
        'google_login_enabled' => $state !== null && (bool) $state['google_login_enabled'],
        'google_oauth_client_id' => trim((string) (getenv('PORTAL_GOOGLE_OAUTH_CLIENT_ID') ?: '')),
        'google_oauth_client_secret' => (string) (getenv('PORTAL_GOOGLE_OAUTH_CLIENT_SECRET') ?: ''),
        'google_oauth_redirect_uri' => trim((string) (getenv('PORTAL_GOOGLE_OAUTH_REDIRECT_URI') ?: '')),
        'google_ca_bundle' => trim((string) (getenv('PORTAL_GOOGLE_CA_BUNDLE') ?: '')),
    ];
    $config['google_login_enabled'] = portal_google_login_configured($config);
    return $config;
}

function portal_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (!is_dir((string) $config['session_save_path']) || !is_writable((string) $config['session_save_path'])) throw new RuntimeException('工作階段儲存區尚未正確設定。');
    ini_set('display_errors', '0'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_strict_mode', '1'); ini_set('session.cookie_httponly', '1');
    session_save_path((string) $config['session_save_path']); session_name('twwater_portal');
    session_set_cookie_params(['lifetime'=>0,'path'=>(string)$config['cookie_path'],'secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function portal_send_security_headers(): void { header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store, max-age=0'); header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin'); header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self' data: blob:; style-src 'self'"); }
function portal_enforce_canonical_application_path(): void { $uri=(string)($_SERVER['REQUEST_URI']??''); $parts=parse_url($uri); $path=is_array($parts)?(string)($parts['path']??''):''; if ($path !== '' && strcasecmp($path,'/gary/TWWATER/') !== 0) { $query=is_array($parts)&&isset($parts['query'])?'?'.(string)$parts['query']:''; header('Location: https://aiwork.ddns.net/gary/TWWATER/'.$query,true,308); exit; } }
function portal_enforce_https(): void { $https=strtolower((string)($_SERVER['HTTPS']??'')); if (($https !== '' && $https !== 'off') || (string)($_SERVER['SERVER_PORT']??'')==='443') return; $uri=(string)($_SERVER['REQUEST_URI']??'/gary/TWWATER/'); if (preg_match('#\A/gary/TWWATER(?:/|\?|\z)#',$uri)!==1 || str_contains($uri,"\r") || str_contains($uri,"\n")) $uri='/gary/TWWATER/'; header('Location: https://aiwork.ddns.net'.$uri,true,308); exit; }
function portal_e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8'); }
function portal_trim_text(string $value,int $max): string { $value=trim($value); return function_exists('mb_substr') ? mb_substr($value,0,$max,'UTF-8') : substr($value,0,$max); }
function portal_url(string $action='home',array $parameters=[]): string { $query=$action==='home'?$parameters:array_merge(['action'=>$action],$parameters); return $query===[]?'?':'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986); }
function portal_csrf_token(): string { return (string)($_SESSION['csrf']??''); }
function portal_csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.portal_e(portal_csrf_token()).'">'; }
function portal_assert_csrf(): void { $provided=(string)($_POST['csrf_token']??''); if ($provided==='' || !hash_equals(portal_csrf_token(),$provided)) throw new RuntimeException('安全驗證失敗，請重新操作。'); }
function portal_clear_session(): void { $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE){$c=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$c['path']?:'/','secure'=>(bool)$c['secure'],'httponly'=>(bool)$c['httponly'],'samesite'=>$c['samesite']?:'Lax']);session_destroy();} }
function portal_reset_anonymous_session(): void { $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE){session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));} }
function portal_flash_set(string $kind,string $message): void { $_SESSION['flash']=['kind'=>$kind,'message'=>portal_trim_text($message,500)]; }
function portal_flash_take(): string { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return is_array($f)?'<p class="'.(($f['kind']??'')==='error'?'error-message':'success-message').'" role="status">'.portal_e((string)($f['message']??'')).'</p>':''; }

function portal_current_user(PDO $database): ?array
{
    $id=(int)($_SESSION['user_id']??0); $last=(int)($_SESSION['last_seen_at']??0); $authenticated=(int)($_SESSION['authenticated_at']??0); $now=time();
    if ($id<=0 || $last<=0 || $authenticated<=0) return null;
    if($now-$last>PORTAL_IDLE_TIMEOUT_SECONDS || $now-$authenticated>PORTAL_ABSOLUTE_TIMEOUT_SECONDS){portal_reset_anonymous_session();return null;}
    $s=$database->prepare('SELECT user_id, username, display_name, role_code, login_mode, must_change_password FROM auth_users WHERE user_id=:user_id AND is_enabled=1'); $s->execute([':user_id'=>$id]); $u=$s->fetch();
    if(!is_array($u)){portal_reset_anonymous_session();return null;} $_SESSION['last_seen_at']=$now; return $u;
}
function portal_require_user(PDO $database): array { $u=portal_current_user($database); if($u===null){header('Location: '.portal_url('login'),true,303);exit;} return $u; }
function portal_is_admin(array $user): bool { return (string)($user['role_code']??'')==='admin'; }
function portal_require_admin(PDO $database): array { $u=portal_require_user($database); if(!portal_is_admin($u)){http_response_code(403);portal_render_page('權限不足','<section class="panel narrow"><h1>權限不足</h1><p>此頁面只提供系統管理者。</p></section>',$u);exit;} return $u; }
function portal_complete_login_session(array $user): void { session_regenerate_id(true); $_SESSION['user_id']=(int)$user['user_id'];$_SESSION['authenticated_at']=time();$_SESSION['last_seen_at']=time();$_SESSION['csrf']=bin2hex(random_bytes(32)); }

function portal_document_path_component_is_excluded(string $name): bool { return $name==='' || str_starts_with($name,'~$') || in_array(strtolower($name),['node_modules','vendor','tmp','maintenance-backups','.discord_log','.discord_uploads'],true); }
function portal_relative_path_from_file(string $root,SplFileInfo $file): ?string { $absolute=$file->getRealPath();$prefix=strtolower(rtrim($root,'\\/').DIRECTORY_SEPARATOR);if($absolute===false || !str_starts_with(strtolower($absolute),$prefix))return null;return str_replace(DIRECTORY_SEPARATOR,'/',substr($absolute,strlen(rtrim($root,'\\/'))+1)); }
function portal_scan_document_source(array $config): array { $root=realpath((string)$config['document_root']);if($root===false||!is_dir($root))throw new RuntimeException('文件來源目錄尚未正確設定。');$files=[];$allowed=['txt','pdf','docx','xlsx','pptx'];$filter=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),static fn(SplFileInfo $i):bool=>!$i->isLink()&&!portal_document_path_component_is_excluded($i->getFilename()));foreach(new RecursiveIteratorIterator($filter) as $file){if(!$file instanceof SplFileInfo||!$file->isFile()||$file->isLink())continue;$relative=portal_relative_path_from_file($root,$file);$ext=strtolower($file->getExtension());if($relative===null||!in_array($ext,$allowed,true))continue;if(count($files)>=PORTAL_DOCUMENT_LIST_LIMIT)break;$files[]=['relative_path'=>$relative,'file_name'=>$file->getFilename(),'title'=>pathinfo($file->getFilename(),PATHINFO_FILENAME),'extension'=>$ext,'file_size_bytes'=>$file->getSize(),'source_modified_at'=>gmdate('Y-m-d H:i:s',$file->getMTime())];if(count($files)>PORTAL_DOCUMENT_LIST_LIMIT)break;}return $files; }
function portal_direct_document_id(string $relativePath): string { return rtrim(strtr(base64_encode($relativePath),'+/','-_'),'='); }
function portal_direct_document_relative_path(string $value): ?string { $value=trim($value);if($value===''||preg_match('/\A[A-Za-z0-9_-]{1,2048}\z/',$value)!==1)return null;$decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);if(!is_string($decoded)||$decoded===''||str_contains($decoded,"\0")||preg_match('#(^|/)\.\.?(?:/|$)#',$decoded)===1||preg_match('#\A(?:[A-Za-z]:|[\\\\/])#',$decoded)===1)return null;return portal_direct_document_id($decoded)===$value?$decoded:null; }
function portal_direct_library_documents(array $config, array $filters): array { $docs=[];foreach(portal_scan_document_source($config) as $f){$f['public_id']=portal_direct_document_id((string)$f['relative_path']);$docs[]=$f;}$q=portal_trim_text((string)($filters['search']??''),120);if($q!=='')$docs=array_values(array_filter($docs,static fn($d)=>stripos((string)$d['relative_path'],$q)!==false));$sort=(string)($filters['sort']??'modified_desc');usort($docs,static function($a,$b)use($sort){$n=strnatcasecmp((string)$a['relative_path'],(string)$b['relative_path']);$m=strcmp((string)$a['source_modified_at'],(string)$b['source_modified_at']);return match($sort){'name_asc'=>$n,'name_desc'=>-$n,'modified_asc','created_asc'=>$m?:$n,default=>-$m?:$n};});return $docs; }
function portal_direct_document_path(array $config, string $id): ?array { $relative=portal_direct_document_relative_path($id);$root=realpath((string)$config['document_root']);if($relative===null||$root===false)return null;foreach(portal_direct_library_documents($config,['sort'=>'name_asc']) as $doc){if(hash_equals((string)$doc['public_id'],$id)){$path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));if($path!==false&&is_file($path)&&str_starts_with(strtolower($path),strtolower(rtrim($root,'\\/').DIRECTORY_SEPARATOR)))return ['document'=>$doc,'path'=>$path];}}return null; }
function portal_document_content_disposition(string $extension,bool $inline): string { return $inline&&in_array($extension,['pdf'],true)?'inline':'attachment'; }
function portal_direct_folder_id(string $relativePath): string { return $relativePath === '' ? 'root' : rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '='); }
function portal_direct_folder_relative_path(string $value): ?string { if ($value === 'root') return ''; return portal_direct_document_relative_path($value); }
function portal_direct_tree_add_folder(array &$nodes, string $path): void { $path=trim($path,'/');if($path===''||isset($nodes[$path]))return;$parent=str_contains($path,'/')?substr($path,0,strrpos($path,'/')):'';portal_direct_tree_add_folder($nodes,$parent);$nodes[$path]=['path'=>$path,'name'=>basename($path),'folders'=>[],'documents'=>[],'direct_count'=>0];$nodes[$parent]['folders'][]=$path; }
function portal_direct_library_folder_tree(array $config,array $filters): array { $root=realpath((string)$config['document_root']);if($root===false||!is_dir($root))throw new RuntimeException('文件來源目錄尚未正確設定。');$nodes=[''=>['path'=>'','name'=>'文件庫','folders'=>[],'documents'=>[],'direct_count'=>0]];$filter=new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),static fn(SplFileInfo $i):bool=>!$i->isLink()&&!portal_document_path_component_is_excluded($i->getFilename()));foreach(new RecursiveIteratorIterator($filter,RecursiveIteratorIterator::SELF_FIRST) as $item){if(!$item instanceof SplFileInfo||!$item->isDir()||$item->isLink())continue;$absolute=$item->getRealPath();$prefix=rtrim($root,'\\/').DIRECTORY_SEPARATOR;if($absolute===false||!str_starts_with(strtolower($absolute),strtolower($prefix)))continue;portal_direct_tree_add_folder($nodes,str_replace(DIRECTORY_SEPARATOR,'/',substr($absolute,strlen($prefix))));}foreach(portal_direct_library_documents($config,$filters) as $document){$parent=str_contains((string)$document['relative_path'],'/')?substr((string)$document['relative_path'],0,strrpos((string)$document['relative_path'],'/')):'';portal_direct_tree_add_folder($nodes,$parent);$nodes[$parent]['documents'][]=$document;}foreach($nodes as &$node){usort($node['folders'],static fn($a,$b)=>strnatcasecmp((string)$nodes[$a]['name'],(string)$nodes[$b]['name']));$node['direct_count']=count($node['folders'])+count($node['documents']);}unset($node);return $nodes; }
function portal_format_file_size(int $bytes): string { return $bytes<1024?$bytes.' B':($bytes<1048576?number_format($bytes/1024,1).' KB':number_format($bytes/1048576,1).' MB'); }
function portal_format_datetime(?string $value): string { $t=$value===null?false:strtotime($value.' UTC');return $t===false?'—':gmdate('Y-m-d H:i',$t); }
function portal_render_page(string $title,string $content,?array $user=null,bool $unused=false,string $class=''):void { if($user!==null&&!headers_sent()){header('Cache-Control: no-store, no-cache, must-revalidate');header('Pragma: no-cache');header('Expires: 0');}$css=is_file(dirname(__DIR__).'/assets/app.css')?(string)filemtime(dirname(__DIR__).'/assets/app.css'):'1';$js=is_file(dirname(__DIR__).'/assets/app.js')?(string)filemtime(dirname(__DIR__).'/assets/app.js'):'1';$nav='';$area='';if($user!==null){$nav='<nav class="site-nav"><a href="'.portal_url('documents').'">文件庫</a>'.(portal_is_admin($user)?'<a href="'.portal_url('admin').'">管理後台</a>':'').'</nav>';$area='<form method="post" action="'.portal_url('logout').'" class="logout-form">'.portal_csrf_field().'<span>'.portal_e((string)$user['display_name']).'（'.portal_e((string)$user['role_code']).'）</span><button type="submit" class="link-button">登出</button></form>';}$class=preg_replace('/[^a-zA-Z0-9_-]+/',' ',trim($class));echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.portal_e($title).'｜供水監測文件平台</title><link rel="stylesheet" href="assets/app.css?v='.portal_e($css).'"></head><body'.($class===''?'':' class="'.portal_e($class).'"').'><header class="site-header"><a class="brand" href="'.($user===null?portal_url('login'):portal_url('documents')).'"><span>供水監測</span><small>文件管理平台</small></a>'.$nav.$area.'</header><main class="page-shell">'.portal_flash_take().$content.'</main><script src="assets/app.js?v='.portal_e($js).'"></script></body></html>'; }
