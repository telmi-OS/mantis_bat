<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';
/** @var MantisBat\Config $config */
$config = $services['config'];

$target = $config->isInstalled() ? 'status.php' : 'install.php';
header('Location: ' . $target, true, 302);
