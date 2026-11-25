<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$services['config']->installed() || !$services['security']->equals((string)$services['config']->get('app.cron_secret',''), (string)($_GET['key']??''))) {
    http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Not found.']); exit;
}
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'GET required.']); exit; }
$lockPath=dirname(__DIR__).'/storage/cron.lock';
$lock=fopen($lockPath,'c');
if($lock===false||!flock($lock,LOCK_EX|LOCK_NB)){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'A Private Mail sync is already running.']);exit;}
@chmod($lockPath,0600);
try{
    $sync=(new MantisBat\PrivateMail\ImapSync($services['config'],$services['storage']))->sync(30);
    $processing=$services['processor']->processPending(10);
    echo json_encode(['ok'=>true,'sync'=>$sync,'processing'=>$processing,'time'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $exception){
    error_log('[Private Mail cron] '.$exception->getMessage());
    $logPath=dirname(__DIR__).'/storage/private_mail.log';
    @file_put_contents($logPath,'['.gmdate(DATE_ATOM).'] '.$exception->getMessage()."\n",FILE_APPEND|LOCK_EX);
    @chmod($logPath,0600);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Private Mail sync failed. Check the protected Status page and private server log.']);
}finally{flock($lock,LOCK_UN);fclose($lock);}
