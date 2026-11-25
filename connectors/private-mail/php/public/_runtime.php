<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
function privateMailH(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function privateMailCsrf(array $services): string
{
    if (!isset($_SESSION['private_mail_app_csrf']) || !is_string($_SESSION['private_mail_app_csrf'])) $_SESSION['private_mail_app_csrf'] = $services['security']->token(24);
    return $_SESSION['private_mail_app_csrf'];
}
function privateMailCheckCsrf(array $services): void
{
    $provided = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!$services['security']->equals(privateMailCsrf($services), $provided)) throw new RuntimeException('Your session token expired. Reload the mail page and try again.');
}
function privateMailRequireAccess(array $services): void
{
    if (!$services['config']->installed()) { header('Location: install.php', true, 302); exit; }
    if (($_SESSION['private_mail_access'] ?? false) === true) return;
    $provided = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    $expected = (string) $services['config']->get('app.access_secret', '');
    if ($services['security']->equals($expected, $provided)) {
        session_regenerate_id(true);
        $_SESSION['private_mail_access'] = true;
        header('Location: index.php', true, 302);
        exit;
    }
    http_response_code(404);
    exit('Not found.');
}
