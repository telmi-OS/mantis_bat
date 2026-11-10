<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
require_once $moduleRoot . '/src/Config.php';

return [
    'moduleRoot' => $moduleRoot,
    'config' => new MantisBat\GhostShell\Config($moduleRoot . '/storage/config.php'),
];
