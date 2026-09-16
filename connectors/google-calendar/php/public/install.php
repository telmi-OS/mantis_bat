<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
$installer = $services['installer'];
$config = $services['config'];
$requirements = $installer->requirements();
$locked = $installer->isLocked();
$csrf = $_SESSION['gcc_install_csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['gcc_install_csrf'] = $csrf;
$error = '';
$success = '';
$defaults = [
    'base_url' => (string) ($_POST['base_url'] ?? ''),
    'timezone' => (string) ($_POST['timezone'] ?? 'Europe/Berlin'),
    'google_client_id' => (string) ($_POST['google_client_id'] ?? ''),
    'google_client_secret' => (string) ($_POST['google_client_secret'] ?? ''),
    'ghost_api_base' => (string) ($_POST['ghost_api_base'] ?? 'https://dev.telmi-ai.com/api/ghost/v2'),
    'ghost_api_token' => (string) ($_POST['ghost_api_token'] ?? ''),
    'group_name' => (string) ($_POST['group_name'] ?? ''),
    'password' => (string) ($_POST['password'] ?? ''),
];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($locked) throw new RuntimeException('Installation is already locked.');
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Invalid installer session token.');
        if (!$installer->requirementsPass()) throw new RuntimeException('Server requirements are not satisfied.');
        if (trim($defaults['base_url']) === '') throw new RuntimeException('Application base URL is required.');
        [$newConfig, $masterKey] = $installer->createConfig($defaults);
        $config->write($newConfig);
        $storage = new MantisBat\GoogleCalendar\Storage($installer->databasePath(), $masterKey);
        $storage->migrate();
        $storage->set('google_client_id', $defaults['google_client_id']);
        $storage->set('google_client_secret', $defaults['google_client_secret']);
        $storage->set('ghost_api_base', rtrim($defaults['ghost_api_base'], '/'));
        $storage->set('ghost_api_token', $defaults['ghost_api_token']);
        $storage->set('group_name', $defaults['group_name']);
        $storage->set('interval_minutes', '15');
        $promptPath = $moduleRoot . '/templates/sync-prompt.txt';
        $storage->set('prompt_template', trim((string) file_get_contents($promptPath)));
        $installer->lock();
        $cronUrl = rtrim($defaults['base_url'], '/') . '/cron.php?key=' . rawurlencode((string) $newConfig['app']['cron_secret']);
        $success = "Installation complete.\nConnect Google Calendar from the dashboard.\nCron URL: {$cronUrl}";
        $locked = true;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Google Calendar Connector</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="shell"><h1>Google Calendar <span>Connector</span></h1><p class="lead">A standalone Mantis Bat connector that sends calendar schedules to a telmi OS Ghost.</p><p class="muted"><a href="https://github.com/telmi-OS/mantis_bat/blob/feature/init-repo/docs/google-calendar-connector.md#google-cloud-oauth-setup" target="_blank" rel="noopener noreferrer">Read the Google OAuth setup guide</a> before creating your client.</p><?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><?php if ($success !== ''): ?><div class="alert success"><pre><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></pre><p><a class="button" href="login.php">Open dashboard</a></p></div><?php endif; ?><section class="grid"><div class="card"><h2>Requirements</h2><ul><?php foreach ($requirements as $name => $passed): ?><li><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>: <strong class="<?= $passed ? 'ok' : 'bad' ?>"><?= $passed ? 'ok' : 'missing' ?></strong></li><?php endforeach; ?></ul><p class="muted">Encryption uses bundled plain PHP code and requires no Sodium, OpenSSL, SQLCipher, Composer, or Google PHP module.</p></div><div class="card"><h2>Install</h2><?php if ($locked): ?><p>Installation is locked. Use the dashboard login.</p><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><label>Application base URL<input name="base_url" value="<?= htmlspecialchars($defaults['base_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/connectors/google-calendar/public" required></label><label>Timezone<input name="timezone" value="<?= htmlspecialchars($defaults['timezone'], ENT_QUOTES, 'UTF-8') ?>" required></label><label>Application password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Google OAuth client ID<input name="google_client_id" value="<?= htmlspecialchars($defaults['google_client_id'], ENT_QUOTES, 'UTF-8') ?>" required></label><label>Google OAuth client secret<input type="password" name="google_client_secret" value="<?= htmlspecialchars($defaults['google_client_secret'], ENT_QUOTES, 'UTF-8') ?>" required></label><label>Ghost API base<input name="ghost_api_base" value="<?= htmlspecialchars($defaults['ghost_api_base'], ENT_QUOTES, 'UTF-8') ?>" required></label><label>Ghost JWT<input type="password" name="ghost_api_token" required></label><label>Target group name<input name="group_name" value="<?= htmlspecialchars($defaults['group_name'], ENT_QUOTES, 'UTF-8') ?>" required><small>Used exactly as entered in natural-language Ghost messages.</small></label><button type="submit">Install connector</button></form><?php endif; ?></div></section></main></body></html>
