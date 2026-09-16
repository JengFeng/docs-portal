<?php
declare(strict_types=1);

function portal_list_admin_management_audit(PDO $database, array $filters): array
{
    $where=[];$params=[];$event=trim((string)($filters['event_type']??''));
    if($event!==''&&preg_match('/\A[a-z0-9_]{1,64}\z/',$event)===1){$where[]='event_type=:event_type';$params[':event_type']=$event;}
    $sql='SELECT event_id, occurred_at, actor_user_id, event_type, target_user_id, summary_json, previous_event_hash, event_hash FROM auth_management_audit_events'.($where===[]?'':' WHERE '.implode(' AND ',$where)).' ORDER BY event_id DESC LIMIT 200';
    $s=$database->prepare($sql);$s->execute($params);return $s->fetchAll();
}
