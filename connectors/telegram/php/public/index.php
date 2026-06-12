<?php

declare(strict_types=1);

$moduleRoot = require __DIR__ . '/_module_root.php';
$services = require $moduleRoot . '/src/bootstrap.php';
/** @var MantisBat\Config $config */
$config = $services['config'];

$target = $config->isInstalled() ? 'status.php' : 'install.php';
header('Location: ' . $target, true, 302);
