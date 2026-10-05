<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$installer = $services['installer'];
$security = $services['security'];
$config = $services['config'];
$requirements = $installer->requirements();
$locked = is_file($installer->lockPath());
$message = '';
$error = '';
$urls = [];
$setupComplete = false;

if (!isset($_SESSION['nextcloud_talk_installer_secret'])) $_SESSION['nextcloud_talk_installer_secret'] = $security->randomToken(12);
$installerSecret = (string) $_SESSION['nextcloud_talk_installer_secret'];

if ($locked && isset($_GET['unlock']) && is_string($_GET['unlock'])) {
    $existing = new MantisBat\NextcloudTalkConnect\Config($installer->configPath());
    if ($security->verifySecret($_GET['unlock'], (string) $existing->get('app.installer_secret_hash', ''))) {
        $_SESSION['nextcloud_talk_installer_unlocked'] = true;
        header('Location: install.php', true, 302);
        exit;
    }
}
$unlockAllowed = !$locked || (($_SESSION['nextcloud_talk_installer_unlocked'] ?? false) === true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        nextcloudTalkCheckCsrf($services);
        if ($locked && !$unlockAllowed) throw new RuntimeException('Installation is locked. Use the private installer unlock URL to change configuration.');
        if (!$installer->requirementsPass()) throw new RuntimeException('The server requirements are not satisfied.');

        $appBase = rtrim(trim((string) ($_POST['app_base_url'] ?? '')), '/');
        $nextcloudBase = rtrim(trim((string) ($_POST['nextcloud_base_url'] ?? '')), '/');
        $username = trim((string) ($_POST['nextcloud_username'] ?? ''));
        $appPassword = trim((string) ($_POST['nextcloud_app_password'] ?? ''));
        $defaultName = trim((string) ($_POST['default_meeting_name'] ?? 'Teleport AI Meeting'));
        foreach (['Application URL' => $appBase, 'Nextcloud URL' => $nextcloudBase] as $label => $url) {
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException($label . ' must be an absolute HTTPS URL without query parameters or credentials.');
            }
        }
        if ($username === '' || $appPassword === '') throw new RuntimeException('Enter the Nextcloud service account username and app password.');
        if ($defaultName === '') $defaultName = 'Teleport AI Meeting';
        if (preg_match('//u', $defaultName) !== 1) throw new RuntimeException('The default meeting name must be valid UTF-8.');
        $defaultName = preg_replace('/\p{Cc}+/u', '', $defaultName) ?? '';
        $defaultName = preg_replace('/\s+/u', ' ', trim($defaultName)) ?? '';
        $defaultName = mb_substr($defaultName !== '' ? $defaultName : 'Teleport AI Meeting', 0, 100, 'UTF-8');

        $client = new MantisBat\NextcloudTalkConnect\TalkClient($nextcloudBase, $username, $appPassword, 10);
        $client->probe();

        $apiKey = $security->randomToken(32);
        $accessSecret = $security->randomToken(24);
        $statusSecret = $security->randomToken(24);
        $configData = [
            'app' => [
                'installed' => true, 'base_url' => $appBase, 'timezone' => 'UTC', 'version' => '0.1.0',
                'status_secret' => $statusSecret, 'access_secret' => $accessSecret,
                'installer_secret_hash' => $security->hashSecret($installerSecret),
            ],
            'api' => ['auth_key' => $apiKey],
            'nextcloud' => ['base_url' => $nextcloudBase, 'username' => $username, 'app_password' => $appPassword, 'timeout_seconds' => 10],
            'meeting' => ['default_name' => $defaultName, 'name_max_length' => 100, 'dedupe_seconds' => 300],
        ];
        $config->write($configData);
        $installer->lock();
        $urls = [
            'Protected app and presets' => $appBase . '/index.php?key=' . rawurlencode($accessSecret),
            'Ghost GET endpoint' => $appBase . '/api/meet/create',
            'Status' => $appBase . '/status.php?key=' . rawurlencode($statusSecret),
            'Health' => $appBase . '/health.php?key=' . rawurlencode($statusSecret),
            'Maintenance' => $appBase . '/maintenance.php?key=' . rawurlencode($statusSecret),
            'Installer unlock URL' => $appBase . '/install.php?unlock=' . rawurlencode($installerSecret),
        ];
        unset($_SESSION['nextcloud_talk_installer_secret'], $_SESSION['nextcloud_talk_installer_unlocked']);
        $_SESSION['nextcloud_talk_installed_output'] = ['urls' => $urls, 'api_key' => $apiKey];
        $message = 'Installation complete. Nextcloud credentials were validated and the Ghost API key was generated.';
        $setupComplete = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$installedOutput = $_SESSION['nextcloud_talk_installed_output'] ?? null;
if (is_array($installedOutput)) {
    $urls = is_array($installedOutput['urls'] ?? null) ? $installedOutput['urls'] : [];
    $apiKeyOutput = (string) ($installedOutput['api_key'] ?? '');
    unset($_SESSION['nextcloud_talk_installed_output']);
} else {
    $apiKeyOutput = '';
}
$csrf = nextcloudTalkCsrf($services);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nextcloud Talk Connect Install | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<main class="page-shell">
    <section class="hero">
        <div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Standalone Mantis Bat Tool</p><h1><span class="gradient-text">Nextcloud Talk Connect</span></h1></div></div>
        <p class="copy">Connect a Nextcloud service account and expose a protected meeting-link endpoint for a telmi OS Ghost.</p>
    </section>

    <?php if ($message !== ''): ?><section class="card"><h2><span class="gradient-text">Setup complete</span></h2><p><?= nextcloudTalkH($message) ?></p><?php foreach ($urls as $label => $url): ?><p><strong><?= nextcloudTalkH($label) ?></strong><br><code><?= nextcloudTalkH($url) ?></code></p><?php endforeach; ?><?php if ($apiKeyOutput !== ''): ?><p><strong>Ghost X-Auth key</strong><br><code><?= nextcloudTalkH($apiKeyOutput) ?></code></p><p class="field-help">Save this key securely. It is shown once here and used by the Ghost preset.</p><?php endif; ?></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="card alert-card"><h2>Setup issue</h2><p><?= nextcloudTalkH($error) ?></p></section><?php endif; ?>

    <div class="card-grid">
        <section class="card"><h2><span class="gradient-text">Requirements</span></h2><ul class="clean-list"><?php foreach ($requirements as $name => $ok): ?><li><img src="assets/mantis-pixel-bullet.svg" alt=""><span><?= nextcloudTalkH(str_replace('_', ' ', $name)) ?>: <strong class="<?= $ok ? 'status-ok' : 'status-bad' ?>"><?= $ok ? 'ready' : 'missing' ?></strong></span></li><?php endforeach; ?></ul><p class="field-help">Expose only <code>public/</code>. Keep <code>storage/</code> private and writable by PHP.</p></section>
        <section class="card">
            <?php if ($setupComplete): ?>
                <h2><span class="gradient-text">Installed</span></h2><p>Save the private URLs and X-Auth key shown above. Open the protected app URL to copy the complete Ghost preset.</p>
            <?php elseif ($locked && !$unlockAllowed): ?>
                <h2><span class="gradient-text">Installer locked</span></h2><p>The app is installed. Use the private installer unlock URL from your setup output to replace this configuration.</p>
            <?php else: ?>
                <h2><span class="gradient-text"><?= $locked ? 'Update configuration' : 'Connect Nextcloud' ?></span></h2>
                <p class="copy">The service account credentials stay on this server. Mantis Bat generates a separate X-Auth key for Ghost calls.</p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= nextcloudTalkH($csrf) ?>">
                    <label>Application base URL<input name="app_base_url" value="<?= nextcloudTalkH((string) ($_POST['app_base_url'] ?? '')) ?>" placeholder="https://example.com/nextcloud-talk-connect/public" required><span class="field-help">The public URL for this tool's <code>public/</code> directory.</span></label>
                    <label>Nextcloud base URL<input name="nextcloud_base_url" value="<?= nextcloudTalkH((string) ($_POST['nextcloud_base_url'] ?? '')) ?>" placeholder="https://cloud.example.com/nextcloud" required></label>
                    <label>Nextcloud service account username<input name="nextcloud_username" value="<?= nextcloudTalkH((string) ($_POST['nextcloud_username'] ?? '')) ?>" autocomplete="username" required></label>
                    <label>Nextcloud app password<input type="password" name="nextcloud_app_password" autocomplete="new-password" required><span class="field-help">Use a dedicated Nextcloud account and app password.</span></label>
                    <label>Default meeting name<input name="default_meeting_name" value="<?= nextcloudTalkH((string) ($_POST['default_meeting_name'] ?? 'Teleport AI Meeting')) ?>" maxlength="100"><span class="field-help">Names are trimmed, whitespace is collapsed, and names longer than 100 characters are shortened.</span></label>
                    <label>Installer unlock secret<input type="password" value="<?= nextcloudTalkH($installerSecret) ?>" readonly><span class="field-help">Save the private installer unlock URL shown after setup.</span></label>
                    <button type="submit">Validate Nextcloud and install</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
