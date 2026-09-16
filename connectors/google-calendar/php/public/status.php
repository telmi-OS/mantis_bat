<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
$config = $services['config'];
if (!$config->installed()) { http_response_code(404); echo 'Not found.'; exit; }
$key = is_string($_GET['key'] ?? null) ? (string) $_GET['key'] : '';
if (!hash_equals((string) $config->get('app.cron_secret', ''), $key)) { http_response_code(404); echo 'Not found.'; exit; }
try {
    $master = $services['auth']->cronKey();
    $storage = new MantisBat\GoogleCalendar\Storage($services['installer']->databasePath(), $master);
    $storage->migrate();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'summary' => $storage->summary(), 'paused' => $storage->get('paused', '0') === '1', 'calendar' => $storage->get('calendar_name', ''), 'group' => $storage->get('group_name', ''), 'last_sync_at' => $storage->get('last_sync_at')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Status unavailable.'], JSON_UNESCAPED_SLASHES);
}
