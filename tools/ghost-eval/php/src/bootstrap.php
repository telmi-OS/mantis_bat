<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
foreach (['Security', 'Config', 'Installer', 'Storage', 'GhostClient', 'EvaluationRunner'] as $file) {
    require_once $moduleRoot . '/src/' . $file . '.php';
}

$security = new MantisBat\GhostEval\Security();
$config = new MantisBat\GhostEval\Config($moduleRoot . '/storage/config.php');
$installer = new MantisBat\GhostEval\Installer($moduleRoot);
$storage = new MantisBat\GhostEval\Storage($installer->databasePath());
$storage->migrate();
$ghost = new MantisBat\GhostEval\GhostClient(
    (string) $config->get('ghost.api_base', 'https://dev.telmi-ai.com/api/ghost/v2'),
    (string) $config->get('ghost.api_token', '')
);
$runner = new MantisBat\GhostEval\EvaluationRunner($config, $storage, $installer);

return compact('moduleRoot', 'security', 'config', 'installer', 'storage', 'ghost', 'runner');
