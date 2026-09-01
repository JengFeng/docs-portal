<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$db=portal_open_database(portal_config());
try{
    $db->beginTransaction();
    $document=$db->query("SELECT TOP (1) document_id FROM dbo.documents WHERE LOWER(extension) IN ('png','jpg','jpeg','webp','gif') ORDER BY document_id")->fetch();
    $user=$db->query("SELECT TOP (1) user_id FROM dbo.auth_users WHERE is_active=1 ORDER BY CASE WHEN role_code='admin' THEN 0 ELSE 1 END,user_id")->fetch();
    if($document===false||$user===false) throw new RuntimeException('fixture unavailable');
    $hash=str_repeat('a',64); $geometry='{"x":0.1,"y":0.2,"width":0.3,"height":0.25,"x1":0.1,"y1":0.2,"x2":0.4,"y2":0.45}'; $style='{"stroke":"#d83b3b","strokeWidth":3}';
    $insert=$db->prepare("INSERT INTO dbo.image_annotations(document_id,source_content_hash,x_norm,y_norm,width_norm,height_norm,note_text,annotation_type_code,geometry_json,style_json,created_by_user_id,updated_by_user_id) OUTPUT CONVERT(varchar(36),inserted.public_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([(int)$document['document_id'],$hash,.1,.2,.3,.25,'DB integration arrow','arrow',$geometry,$style,(int)$user['user_id'],(int)$user['user_id']]);
    $id=(string)$insert->fetchColumn();
    $read=$db->prepare("SELECT annotation_type_code,ISJSON(geometry_json) geometry_valid,ISJSON(style_json) style_valid FROM dbo.image_annotations WHERE public_id=CONVERT(uniqueidentifier,?)");$read->execute([$id]);$row=$read->fetch();
    if($row===false||$row['annotation_type_code']!=='arrow'||(int)$row['geometry_valid']!==1||(int)$row['style_valid']!==1) throw new RuntimeException('typed annotation readback failed');
    $update=$db->prepare("UPDATE dbo.image_annotations SET annotation_type_code='text',geometry_json=?,style_json=? WHERE public_id=CONVERT(uniqueidentifier,?)");$update->execute(['{"x":0.1,"y":0.2,"width":0.3,"height":0.1,"text":"integration"}','{"textColor":"#d83b3b","fontSize":24}',$id]);
    if($update->rowCount()!==1) throw new RuntimeException('typed annotation update failed');
    $db->rollBack(); echo "[OK] typed image annotation database insert/update rolled back.\n";
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();fwrite(STDERR,"[FAIL] typed image annotation database integration failed: ".$e->getMessage()."\n");exit(1);}
