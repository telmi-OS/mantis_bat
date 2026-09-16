<?php

declare(strict_types=1);

use MantisBat\GoogleCalendar\Auth;
use MantisBat\GoogleCalendar\Config;
use MantisBat\GoogleCalendar\Crypto;
use MantisBat\GoogleCalendar\Installer;
use MantisBat\GoogleCalendar\PromptBuilder;

$moduleRoot = dirname(__DIR__);
foreach (['Crypto', 'Config', 'Installer', 'Auth', 'Storage', 'GoogleClient', 'GhostClient', 'PromptBuilder', 'SyncService'] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

$crypto = new Crypto();
$config = new Config($moduleRoot . '/storage/config.php');
date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
$installer = new Installer($moduleRoot, $crypto);
$auth = new Auth($config, $crypto);

return [
    'root' => $moduleRoot,
    'crypto' => $crypto,
    'config' => $config,
    'installer' => $installer,
    'auth' => $auth,
];
