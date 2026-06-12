<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];

$target = $config->isInstalled() ? 'status.php' : 'install.php';
header('Location: ' . $target, true, 302);
