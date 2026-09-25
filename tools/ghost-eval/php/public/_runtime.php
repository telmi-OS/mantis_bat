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

function ghostEvalH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ghostEvalCsrf(array $services): string
{
    if (!isset($_SESSION['ghost_eval_csrf']) || !is_string($_SESSION['ghost_eval_csrf'])) {
        $_SESSION['ghost_eval_csrf'] = $services['security']->randomToken(16);
    }
    return $_SESSION['ghost_eval_csrf'];
}

function ghostEvalCheckCsrf(array $services): void
{
    $provided = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!$services['security']->equals(ghostEvalCsrf($services), $provided)) {
        throw new RuntimeException('Your session token expired. Reload the page and try again.');
    }
}

function ghostEvalHasAccess(array $services): bool
{
    if (($_SESSION['ghost_eval_access'] ?? false) === true) return true;
    $provided = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    $expected = (string) $services['config']->get('app.access_secret', '');
    if ($services['config']->isInstalled() && $services['security']->equals($expected, $provided)) {
        session_regenerate_id(true);
        $_SESSION['ghost_eval_access'] = true;
        return true;
    }
    return false;
}

function ghostEvalRequireAccess(array $services): void
{
    if (!ghostEvalHasAccess($services)) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }
}
