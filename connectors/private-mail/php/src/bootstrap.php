<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
foreach (['Config','Security','Storage','GhostClient','ImapClient','MimeParser','ImapSync','Smtp','MailProcessor','MailOperations'] as $file) {
    require_once $moduleRoot . '/src/' . $file . '.php';
}

$config = new MantisBat\PrivateMail\Config($moduleRoot . '/storage/config.php');
$security = new MantisBat\PrivateMail\Security();
$storage = new MantisBat\PrivateMail\Storage($moduleRoot . '/storage/private_mail.sqlite');
$storage->migrate();
$ghost = new MantisBat\PrivateMail\GhostClient(
    (string) $config->get('ghost.api_base', ''),
    (string) $config->get('ghost.api_token', ''),
    (string) $config->get('app.base_url', '')
);
$processor = new MantisBat\PrivateMail\MailProcessor($config, $storage, $ghost);
$operations = new MantisBat\PrivateMail\MailOperations($config, $storage, $ghost);

return compact('moduleRoot','config','security','storage','ghost','processor','operations');
