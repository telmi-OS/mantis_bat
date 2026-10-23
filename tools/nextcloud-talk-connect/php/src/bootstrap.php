<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
foreach (['Security', 'Config', 'Installer', 'Storage', 'TalkAuthenticationException', 'TalkClient'] as $file) require_once $moduleRoot . '/src/' . $file . '.php';

$security = new MantisBat\NextcloudTalkConnect\Security();
$config = new MantisBat\NextcloudTalkConnect\Config($moduleRoot . '/storage/config.php');
$installer = new MantisBat\NextcloudTalkConnect\Installer($moduleRoot);
$storage = new MantisBat\NextcloudTalkConnect\Storage($installer->databasePath());
$storage->migrate();
$talk = new MantisBat\NextcloudTalkConnect\TalkClient(
    rtrim((string) $config->get('nextcloud.base_url', ''), '/'),
    (string) $config->get('nextcloud.username', ''),
    (string) $config->get('nextcloud.app_password', ''),
    (int) $config->get('nextcloud.timeout_seconds', 10)
);

return compact('moduleRoot', 'security', 'config', 'installer', 'storage', 'talk');
