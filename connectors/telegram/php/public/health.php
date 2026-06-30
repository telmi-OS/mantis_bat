<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];
/** @var MantisBat\Security $security */
$security = $services['security'];

$owner = $storage->getAuthorizedOwner();
$expectedKey = (string) $config->get('app.health_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;

if ($expectedKey !== '' && !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'installed' => $config->isInstalled(),
    'telegram_configured' => (string) $config->get('telegram.bot_token', '') !== '',
    'ghost_configured' => (string) $config->get('ghost.api_token', '') !== '',
    'paired' => $owner !== null,
    'version' => $config->get('app.version', '0.1.0'),
    'build' => $config->buildFingerprint(),
], JSON_UNESCAPED_SLASHES);
