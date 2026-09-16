<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $services['config'];
$auth = $services['auth'];
if (!$config->installed()) { header('Location: install.php'); exit; }
$key = $auth->requireSessionKey();
$storage = new MantisBat\GoogleCalendar\Storage(dirname(__DIR__) . '/storage/google_calendar.sqlite', $key);
$storage->migrate();
$clientId = (string) $storage->get('google_client_id', '');
$clientSecret = (string) $storage->get('google_client_secret', '');
if ($clientId === '' || $clientSecret === '') { http_response_code(400); echo 'Google OAuth credentials are not configured.'; exit; }
$state = bin2hex(random_bytes(24));
$_SESSION['gcc_oauth_state'] = $state;
$redirect = rtrim((string) $config->get('app.base_url', ''), '/') . '/oauth_callback.php';
$google = new MantisBat\GoogleCalendar\GoogleClient($clientId, $clientSecret, $redirect);
header('Location: ' . $google->authorizationUrl($state));
exit;
