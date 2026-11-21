<?php

declare(strict_types=1);

// Copy to the private Hades storage/config.php only when configuring manually.
// The browser installer writes a private config with generated access keys.
return [
    'app' => [
        'installed' => false,
        'base_url' => '',
        'timezone' => 'Europe/Berlin',
        'version' => '0.1.0',
        'api_key' => '',
        'access_secret' => '',
        'cron_secret' => '',
        'status_secret' => '',
        'health_secret' => '',
        'installer_secret_hash' => '',
    ],
    'owner' => ['name'=>'','email'=>''],
    'signature' => '',
    'imap' => ['host'=>'','port'=>993,'username'=>'','password'=>''],
    'smtp' => ['host'=>'','port'=>465,'username'=>'','password'=>''],
    'ghost' => ['api_base'=>'https://dev.telmi-ai.com/api/ghost/v2','api_token'=>'','name'=>''],
    'files' => ['space_id'=>'','space_label'=>'','folder_id'=>''],
    'mail' => ['folders'=>['inbox'=>'INBOX','sent'=>'Sent','ghost_deleted'=>'Ghost Deleted']],
    'rules' => ['default'=>'summarize_notify'],
    'processing' => ['use_rag'=>true,'use_history'=>true,'batch_size'=>10],
    'notifications' => ['destination'=>['type'=>'owner','id'=>'','label'=>'']],
];
