<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Storage.php';
require dirname(__DIR__).'/src/GhostClient.php';
use MantisBat\PrivateMail\Storage;
use MantisBat\PrivateMail\GhostClient;
$path=tempnam(sys_get_temp_dir(),'private-mail-log-');
try {
    $storage=new Storage($path);$storage->migrate();
    $ghost=new GhostClient('https://example.com','secret','https://example.com',$storage);
    $method=new ReflectionMethod(GhostClient::class,'logResponse');
    for($i=0;$i<11;$i++) $method->invoke($ghost,'POST','/chat',['mode'=>'queued'],200,['job_id'=>'job-'.$i]);
    $method->invoke($ghost,'GET','/settings',null,200,['headers'=>['X-Mail-Key'=>'secret-value']]);
    $rows=$storage->db->query('SELECT * FROM ghost_api_log ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==10 || str_contains((string)$rows[0]['response'],'secret-value') || !str_contains((string)$rows[1]['response'],'job-10')) throw new RuntimeException('Ghost API log retention or redaction failed.');
    echo "Ghost API response log checks passed.\n";
} finally { unset($storage); @unlink($path); @unlink($path.'-wal'); @unlink($path.'-shm'); }
