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
$key=implode('',array_map('chr',range(0,31)));
$binding=['binding_id'=>'22222222-2222-4222-8222-222222222222','issued_utc'=>'2026-08-30T10:00:00Z','expires_utc'=>'2026-08-30T11:00:00Z','public_id'=>'4380862e-aca7-4637-872e-0222ac65d09b','relative_path'=>'infographic/test.png','source_hash'=>str_repeat('a',64),'extension'=>'png','source_width'=>8,'source_height'=>8,'regions'=>'0,0,8,8'];
$actual=portal_image_binding_hmac($binding,$key);
if($actual!=='13d7c60ab6a36a0dcfc6d410576525caaa8e3279c71a3afa109af5d44ab4a067'){throw new RuntimeException('PHP image binding HMAC mismatch: '.$actual);}echo "[OK] PHP image binding HMAC matches the cross-language vector.\n";
