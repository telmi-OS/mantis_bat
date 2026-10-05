<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$security = $services['security'];
$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if (!$config->isInstalled() || !$security->equals((string) $config->get('app.status_secret', ''), $key)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"not_found"}';
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true, 'installed' => $config->isInstalled(), 'version' => $config->get('app.version'),
    'build' => $config->buildFingerprint(), 'meetings' => $services['storage']->counts(),
], JSON_UNESCAPED_SLASHES);
