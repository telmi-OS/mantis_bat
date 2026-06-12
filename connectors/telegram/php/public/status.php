<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var MantisBat\Config $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];

$expectedKey = (string) $config->get('app.status_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;

if ($expectedKey !== '' && !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$status = $config->publicStatus();
require dirname(__DIR__) . '/templates/status.html.php';
