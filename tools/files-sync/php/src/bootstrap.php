<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
require_once $moduleRoot . '/src/Config.php';

$config = new MantisBat\FilesSync\Config($moduleRoot . '/storage/config.php');

return compact('moduleRoot', 'config');
