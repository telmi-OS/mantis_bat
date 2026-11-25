<?php

declare(strict_types=1);

$services=require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$expected=(string)$services['config']->get('app.status_secret','');
if(!$services['config']->installed()||!$services['security']->equals($expected,(string)($_GET['key']??''))){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Not found.']);exit;}
$counts=$services['storage']->db->query('SELECT f.role,m.processing_status,COUNT(*) AS count FROM messages m JOIN folders f ON f.id=m.folder_id GROUP BY f.role,m.processing_status')->fetchAll(PDO::FETCH_ASSOC);
$status=$services['config']->status();
$status['counts']=$counts;
$status['pending_processing']=(int)$services['storage']->db->query("SELECT COUNT(*) FROM messages WHERE processing_status IN ('pending','retry')")->fetchColumn();
$status['uncertain_processing']=(int)$services['storage']->db->query("SELECT COUNT(*) FROM messages WHERE processing_status IN ('delivery_uncertain','submission_uncertain')")->fetchColumn();
$status['ghost_settings_backups']=array_map('basename',glob(dirname(__DIR__).'/storage/ghost-settings-*.json')?:[]);
$status['recent_ghost_api_responses']=$services['storage']->db->query('SELECT created_at,method,path,mode,http_status,response FROM ghost_api_log ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach($status['recent_ghost_api_responses'] as &$entry){$decoded=json_decode((string)$entry['response'],true);if(is_array($decoded))$entry['response']=$decoded;}unset($entry);
echo json_encode(['ok'=>true,'status'=>$status],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
