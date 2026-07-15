<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];

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
    'ghost_configured' => (string) $config->get('ghost.api_token', '') !== '',
    'version' => $config->get('app.version', '0.1.0'),
    'build' => $config->buildFingerprint(),
    'job_counts' => $storage->countJobs(),
], JSON_UNESCAPED_SLASHES);
