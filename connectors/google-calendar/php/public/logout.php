<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
$services['auth']->logout();
session_destroy();
header('Location: login.php');
exit;
