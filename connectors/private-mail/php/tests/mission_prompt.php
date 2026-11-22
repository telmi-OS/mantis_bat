<?php
declare(strict_types=1);
foreach(['Config','Storage','GhostClient','ReplyPlan','MailProcessor'] as $file)require dirname(__DIR__).'/src/'.$file.'.php';
use MantisBat\PrivateMail\{Config,Storage,GhostClient,MailProcessor};
$dir=sys_get_temp_dir().'/private-mail-prompt-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
    $config=new Config($dir.'/config.php');$config->write(['autonomous_ghost'=>['name'=>'Action Ghost','api_token'=>'example'],'owner'=>['email'=>'owner@example.com'],'notifications'=>['destination'=>['label'=>'Team']]]);
    $storage=new Storage($dir.'/mail.sqlite');$storage->migrate();$storage->setRule('sender@example.com',null,'auto');
    $processor=new MailProcessor($config,$storage,new GhostClient('','',''));
    $email=['id'=>7,'subject'=>'Hello','body'=>'Hi','addresses'=>[['kind'=>'from','address'=>'sender@example.com'],['kind'=>'to','address'=>'owner@example.com']]];
    $method=new ReflectionMethod(MailProcessor::class,'taskPrompt');
    $auto=$method->invoke($processor,$email,'A greeting','auto');
    if(!str_starts_with($auto,'Tell Action Ghost please use Private Mail') || !str_contains($auto,'no approval is needed') || str_contains($auto,'private_mail_'))throw new RuntimeException('Automatic mission prompt is incorrect.');
    $storage->setRule('sender@example.com',null,'draft');
    $draft=$method->invoke($processor,$email,'A greeting','auto');
    if(!str_contains($draft,'ask for approval via chat to Team before sending'))throw new RuntimeException('Approval mission prompt is incorrect.');
    echo "Mission prompt checks passed.\n";
} finally { unset($storage); foreach(glob($dir.'/*')?:[] as $file)@unlink($file); @rmdir($dir); }
