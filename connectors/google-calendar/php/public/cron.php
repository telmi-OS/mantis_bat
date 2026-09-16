<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
$config = $services['config'];
$installer = $services['installer'];
$output = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$config->installed() || !$installer->isLocked()) $output(['ok' => false, 'error' => 'Connector is not installed.'], 503);
if (PHP_SAPI !== 'cli') {
    $key = is_string($_GET['key'] ?? null) ? (string) $_GET['key'] : '';
    if (!hash_equals((string) $config->get('app.cron_secret', ''), $key)) $output(['ok' => false, 'error' => 'Invalid cron key.'], 403);
}
$lockHandle = fopen($moduleRoot . '/storage/sync.lock', 'c+');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) $output(['ok' => false, 'error' => 'A sync is already running.'], 409);
try {
    $key = $services['auth']->cronKey();
    $storage = new MantisBat\GoogleCalendar\Storage($installer->databasePath(), $key);
    $storage->migrate();
    $google = new MantisBat\GoogleCalendar\GoogleClient((string) $storage->get('google_client_id', ''), (string) $storage->get('google_client_secret', ''), rtrim((string) $config->get('app.base_url', ''), '/') . '/oauth_callback.php');
    $ghost = new MantisBat\GoogleCalendar\GhostClient((string) $storage->get('ghost_api_base', ''), (string) $storage->get('ghost_api_token', ''));
    $prompts = new MantisBat\GoogleCalendar\PromptBuilder($moduleRoot . '/templates/sync-prompt.txt');
    $service = new MantisBat\GoogleCalendar\SyncService($config, $storage, $google, $ghost, $prompts);
    $output($service->run());
} catch (Throwable $e) {
    $output(['ok' => false, 'error' => 'Cron failed: ' . $e->getMessage()], 500);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
