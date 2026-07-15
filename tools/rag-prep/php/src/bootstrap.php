<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);

foreach ([
    'Security',
    'RuntimeConfig',
    'Storage',
    'Logger',
    'Installer',
    'GhostClient',
    'DocumentExtractor',
    'JobProcessor',
] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

use MantisBat\DocumentExtractor;
use MantisBat\GhostClient;
use MantisBat\Installer;
use MantisBat\JobProcessor;
use MantisBat\Logger;
use MantisBat\RuntimeConfig;
use MantisBat\Security;
use MantisBat\Storage;

$security = new Security();
$config = new RuntimeConfig($moduleRoot . '/storage/config.php');
$installer = new Installer($moduleRoot, $security);
$installer->ensureRuntimeDirectories();
$storage = new Storage($installer->databasePath());
$storage->migrate();
$logger = new Logger(
    $security,
    $storage,
    $installer->logPath(),
    [
        (string) $config->get('ghost.api_token', ''),
        (string) $config->get('app.access_secret', ''),
        (string) $config->get('app.cron_secret', ''),
        (string) $config->get('app.status_secret', ''),
        (string) $config->get('app.health_secret', ''),
    ]
);
$ghost = new GhostClient($config, $logger);
$extractor = new DocumentExtractor($config, $logger);
$jobProcessor = new JobProcessor($config, $storage, $ghost, $extractor, $installer, $logger);

return [
    'module_root' => $moduleRoot,
    'security' => $security,
    'config' => $config,
    'installer' => $installer,
    'storage' => $storage,
    'logger' => $logger,
    'ghost' => $ghost,
    'extractor' => $extractor,
    'job_processor' => $jobProcessor,
];
