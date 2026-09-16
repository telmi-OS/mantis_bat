<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $services['config'];
$auth = $services['auth'];
try {
    $key = $auth->requireSessionKey();
    if (!hash_equals((string) ($_SESSION['gcc_oauth_state'] ?? ''), (string) ($_GET['state'] ?? ''))) throw new RuntimeException('Invalid Google OAuth state.');
    unset($_SESSION['gcc_oauth_state']);
    $storage = new MantisBat\GoogleCalendar\Storage(dirname(__DIR__) . '/storage/google_calendar.sqlite', $key);
    $storage->migrate();
    $google = new MantisBat\GoogleCalendar\GoogleClient((string) $storage->get('google_client_id'), (string) $storage->get('google_client_secret'), rtrim((string) $config->get('app.base_url', ''), '/') . '/oauth_callback.php');
    $token = $google->exchangeCode((string) ($_GET['code'] ?? ''));
    $storage->set('google_access_token', (string) ($token['access_token'] ?? ''));
    $storage->set('google_access_expires_at', (string) (time() + (int) ($token['expires_in'] ?? 3600)));
    if (!empty($token['refresh_token'])) $storage->set('google_refresh_token', (string) $token['refresh_token']);
    header('Location: index.php?connected=1');
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><p>Google connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a href="index.php">Back to dashboard</a></p>';
}
