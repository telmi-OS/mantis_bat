<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

$services = require dirname(__DIR__) . '/src/bootstrap.php';

function nextcloudTalkH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nextcloudTalkCsrf(array $services): string
{
    if (!isset($_SESSION['nextcloud_talk_csrf']) || !is_string($_SESSION['nextcloud_talk_csrf'])) {
        $_SESSION['nextcloud_talk_csrf'] = $services['security']->randomToken(16);
    }
    return $_SESSION['nextcloud_talk_csrf'];
}

function nextcloudTalkCheckCsrf(array $services): void
{
    $provided = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!$services['security']->equals(nextcloudTalkCsrf($services), $provided)) throw new RuntimeException('Your session token expired. Reload the page and try again.');
}

function nextcloudTalkHasAccess(array $services): bool
{
    if (($_SESSION['nextcloud_talk_access'] ?? false) === true) return true;
    $provided = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    $expected = (string) $services['config']->get('app.access_secret', '');
    if ($services['config']->isInstalled() && $services['security']->equals($expected, $provided)) {
        session_regenerate_id(true);
        $_SESSION['nextcloud_talk_access'] = true;
        return true;
    }
    return false;
}

function nextcloudTalkRequireAccess(array $services): void
{
    if (!nextcloudTalkHasAccess($services)) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }
}
