<?php
declare(strict_types=1);
function portal_valid_public_id(string $value): string{return strtolower($value);}
function portal_is_admin(array $user): bool{return true;}
function portal_e(string $value): string{return $value;}
function portal_format_file_size(int $value): string{return (string)$value;}
function portal_format_datetime(string $value): string{return $value;}
function portal_url(string $action,array $params=[]): string{return $action;}
function portal_csrf_field(): string{return '';}
require_once __DIR__.'/../app/document_versions.php';
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'twwater-binding-'.bin2hex(random_bytes(6));mkdir($root);$keyPath=$root.DIRECTORY_SEPARATOR.'image-command.key';file_put_contents($keyPath,implode('',array_map('chr',range(0,31))));$imagePath=$root.DIRECTORY_SEPARATOR.'image.png';file_put_contents($imagePath,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true));
try{$binding=portal_image_build_signed_binding(['image_source_archive_root'=>$root,'image_command_key_path'=>$keyPath],['public_id'=>'4380862e-aca7-4637-872e-0222ac65d09b','relative_path'=>'infographic/test.png','extension'=>'png'],str_repeat('a',64),[['x_norm'=>0.0,'y_norm'=>0.0,'width_norm'=>1.0,'height_norm'=>1.0]],$imagePath);foreach(['binding_id','issued_utc','expires_utc','public_id','relative_path','source_hash','extension','source_width','source_height','regions','binding_hmac'] as $field){if(!array_key_exists($field,$binding)){throw new RuntimeException("missing $field");}}if($binding['source_width']!==1||$binding['source_height']!==1||$binding['regions']!=='0,0,1,1'){throw new RuntimeException('signed binding dimensions/regions mismatch');}$unsigned=$binding;unset($unsigned['binding_hmac']);if(!hash_equals(portal_image_binding_hmac($unsigned,file_get_contents($keyPath)),$binding['binding_hmac'])){throw new RuntimeException('signed binding HMAC mismatch');}echo "[OK] Portal builds a signed catalog/image/ROI binding.\n";}finally{@unlink($imagePath);@unlink($keyPath);@rmdir($root);}
