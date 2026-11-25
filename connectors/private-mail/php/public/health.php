<?php

declare(strict_types=1);

$services=require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$expected=(string)$services['config']->get('app.health_secret','');
if(!$services['config']->installed()||!$services['security']->equals($expected,(string)($_GET['key']??''))){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Not found.']);exit;}
$folders=[];
foreach(['inbox','sent','ghost_deleted'] as $role){$folder=$services['storage']->roleFolder($role);$folders[$role]=$folder?['configured'=>true,'messages'=>(int)$services['storage']->db->query('SELECT COUNT(*) FROM messages WHERE folder_id='.(int)$folder['id'])->fetchColumn(),'last_synced_at'=>$folder['last_synced_at']??'']:['configured'=>false];}
$health=['installed'=>$services['config']->installed(),'php'=>PHP_VERSION,'extensions'=>['curl'=>extension_loaded('curl'),'pdo_sqlite'=>extension_loaded('pdo_sqlite'),'openssl'=>extension_loaded('openssl'),'mbstring'=>extension_loaded('mbstring')],'storage_writable'=>is_writable(dirname(__DIR__).'/storage'),'folders'=>$folders,'cron_lock_active'=>false];
$lockPath=dirname(__DIR__).'/storage/cron.lock';if(is_file($lockPath)){$lock=@fopen($lockPath,'c');if($lock){$health['cron_lock_active']=!flock($lock,LOCK_EX|LOCK_NB);if(!$health['cron_lock_active'])flock($lock,LOCK_UN);fclose($lock);}}
echo json_encode(['ok'=>true,'health'=>$health],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
