<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$services = require dirname(__DIR__) . '/src/bootstrap.php';
$config = $services['config'];
$auth = $services['auth'];
$error = '';
$csrf = $_SESSION['gcc_login_csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['gcc_login_csrf'] = $csrf;
if (!$config->installed()) {
    header('Location: install.php');
    exit;
}
if ($auth->sessionKey() !== null) {
    header('Location: index.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Invalid session token.');
        if (!$auth->login((string) ($_POST['password'] ?? ''))) throw new RuntimeException('Invalid application password.');
        header('Location: index.php');
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Calendar Connector Login</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="shell narrow"><h1>Google Calendar <span>Connector</span></h1><p class="muted">Enter the application password to continue.</p><?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post" class="card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Unlock</button></form></main></body></html>
