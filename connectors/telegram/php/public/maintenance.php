<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];
/** @var MantisBat\TelegramClient $telegram */
$telegram = $services['telegram'];
/** @var MantisBat\Installer $installer */
$installer = $services['installer'];

$expectedKey = (string) $config->get('app.status_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;
$csrfToken = $_SESSION['mantis_bat_maintenance_csrf'] ?? $security->randomToken(16);
$_SESSION['mantis_bat_maintenance_csrf'] = $csrfToken;

if (!$config->isInstalled() || $expectedKey === '' || !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$message = '';
$pairingCode = '';
$pairingLink = '';
$ghostApiBase = (string) $config->get('ghost.api_base', '');
$ghostApiToken = (string) $config->get('ghost.api_token', '');
$ghostDefaultGroupId = (string) $config->get('ghost.default_group_id', '');
$didFactoryReset = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $postedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if ($action === '') {
            throw new RuntimeException('Missing maintenance action.');
        }
        if (!$security->constantTimeEquals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid maintenance session token. Reload the page and try again.');
        }

        if ($action === 'generate_pairing') {
            $bot = $telegram->getMe();
            $botUsername = (string) ($bot['result']['username'] ?? '');
            if ($botUsername === '') {
                throw new RuntimeException('Could not read Telegram bot username.');
            }

            $pairingCode = strtoupper(substr($security->randomToken(8), 0, 6));
            $storage->createPairingCode($pairingCode, time() + 86400);
            $pairingLink = sprintf('https://t.me/%s?start=%s', $botUsername, $pairingCode);
            $message = 'New pairing code created. It stays valid for 24 hours or until it is used once.';
        } elseif ($action === 'unpair_telegram') {
            $storage->clearTelegramRuntimeData();
            $message = 'Telegram owner access cleared. The connector is now unpaired.';
        } elseif ($action === 'disconnect_webhook') {
            $telegram->deleteWebhook();
            $message = 'Telegram webhook removed.';
        } elseif ($action === 'reset_inbox_backend') {
            $storage->resetInboxBackend();
            $message = 'Local inbox backend reset. Pairing, config, and webhook were kept. The next cron run will seed a fresh baseline from telmi OS without replaying old Telegram history.';
        } elseif ($action === 'switch_ghost') {
            $ghostApiBase = isset($_POST['ghost_api_base']) && is_string($_POST['ghost_api_base']) ? rtrim(trim($_POST['ghost_api_base']), '/') : '';
            $ghostApiToken = isset($_POST['ghost_api_token']) && is_string($_POST['ghost_api_token']) ? trim($_POST['ghost_api_token']) : '';
            $ghostDefaultGroupId = isset($_POST['ghost_default_group_id']) && is_string($_POST['ghost_default_group_id']) ? trim($_POST['ghost_default_group_id']) : '';

            if ($ghostApiBase === '' || $ghostApiToken === '') {
                throw new RuntimeException('Ghost API base and Ghost JWT are required.');
            }

            $currentConfig = $config->all();
            $nextConfig = $currentConfig;
            $nextConfig['ghost']['api_base'] = $ghostApiBase;
            $nextConfig['ghost']['api_token'] = $ghostApiToken;
            $nextConfig['ghost']['default_group_id'] = $ghostDefaultGroupId;

            $installer->writeConfig($nextConfig);

            try {
                $nextRuntimeConfig = new MantisBat\RuntimeConfig($installer->configPath());
                $ghost = new MantisBat\GhostClient($nextRuntimeConfig);
                $ghostProbe = $ghost->probeSettings();
                $decodedProbe = json_decode((string) $ghostProbe['body'], true);

                if (!is_array($decodedProbe)) {
                    throw new RuntimeException(sprintf(
                        "Ghost API probe failed.\nURL: %s\nStatus: %d\nContent-Type: %s\nPreview: %s",
                        $ghostProbe['url'],
                        (int) $ghostProbe['status'],
                        (string) (($ghostProbe['content_type'] ?? '') !== '' ? $ghostProbe['content_type'] : 'unknown'),
                        $ghost->preview((string) $ghostProbe['body'])
                    ));
                }

                if ((int) $ghostProbe['status'] >= 400 || (($decodedProbe['ok'] ?? true) === false)) {
                    throw new RuntimeException(sprintf(
                        "Ghost API probe failed.\nURL: %s\nStatus: %d\nError: %s",
                        $ghostProbe['url'],
                        (int) $ghostProbe['status'],
                        (string) ($decodedProbe['error'] ?? 'Unknown Ghost API error')
                    ));
                }
            } catch (Throwable $exception) {
                $installer->writeConfig($currentConfig);
                throw $exception;
            }

            $message = 'Ghost connection updated and validated.';
        } elseif ($action === 'factory_reset') {
            $confirmation = isset($_POST['confirmation']) && is_string($_POST['confirmation']) ? trim($_POST['confirmation']) : '';
            if ($confirmation !== 'RESET') {
                throw new RuntimeException('Factory reset requires typing RESET exactly.');
            }

            try {
                $telegram->deleteWebhook();
            } catch (Throwable) {
            }

            $installer->resetRuntime();
            $didFactoryReset = true;
            $message = 'Factory reset complete. Runtime config, lock file, database, and log were removed. Open install.php to start again.';
        } else {
            throw new RuntimeException('Unknown maintenance action.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS Connector Maintenance | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="page-shell">
    <section class="hero">
        <div class="brand-row">
            <img src="assets/mantis-mini.svg" alt="Mantis Bat">
            <div>
                <p class="eyebrow">Teleport AI Official Connector Repo</p>
                <h1><span class="gradient-text">Connector</span> Maintenance</h1>
            </div>
        </div>
        <p class="copy">Use this protected page to manage the live Telegram connector without reinstalling unless you actually want a full reset.</p>
        <p class="copy"><strong>Version:</strong> <?= htmlspecialchars((string) $config->get('app.version', '0.1.0'), ENT_QUOTES, 'UTF-8') ?> <strong>Build:</strong> <?= htmlspecialchars($config->buildFingerprint(), ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <div class="card-grid">
        <?php if ($message !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Status</span></h2>
                <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>

        <?php if (!$didFactoryReset): ?>
            <section class="card">
                <h2><span class="gradient-text">Pairing</span></h2>
                <p class="copy">Unpair the current Telegram owner, or create a fresh single-use pairing code for a new owner.</p>
                <form method="post">
                    <input type="hidden" name="action" value="unpair_telegram">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Unpair Telegram Owner</button>
                </form>
                <form method="post" style="margin-top:14px;">
                    <input type="hidden" name="action" value="generate_pairing">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Generate New Pairing Code</button>
                </form>
                <?php if ($pairingCode !== ''): ?>
                    <p><strong>Pairing code</strong></p>
                    <pre><?= htmlspecialchars($pairingCode, ENT_QUOTES, 'UTF-8') ?></pre>
                    <p><strong>Telegram deep link</strong></p>
                    <pre><?= htmlspecialchars($pairingLink, ENT_QUOTES, 'UTF-8') ?></pre>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2><span class="gradient-text">Ghost Connection</span></h2>
                <p class="copy">Switch this connector to another Ghost by updating the runtime Ghost API base, JWT, and optional default group. The new settings are probed before the change is kept.</p>
                <form method="post">
                    <input type="hidden" name="action" value="switch_ghost">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label>
                        Ghost API Base
                        <input type="text" name="ghost_api_base" value="<?= htmlspecialchars($ghostApiBase, ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Ghost JWT
                        <input type="text" name="ghost_api_token" value="<?= htmlspecialchars($ghostApiToken, ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Default Group ID
                        <input type="text" name="ghost_default_group_id" value="<?= htmlspecialchars($ghostDefaultGroupId, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit">Update Ghost Connection</button>
                </form>
            </section>

            <section class="card">
                <h2><span class="gradient-text">Telegram Webhook</span></h2>
                <p class="copy">This removes the webhook from Telegram but keeps your local connector config and Ghost settings.</p>
                <form method="post">
                    <input type="hidden" name="action" value="disconnect_webhook">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Delete Telegram Webhook</button>
                </form>
            </section>

            <section class="card">
                <h2><span class="gradient-text">Inbox Backend Reset</span></h2>
                <p class="copy">This clears only the connector-local inbox cache, delivery markers, and baseline flag. It keeps pairing, Ghost config, webhook registration, and the rest of the runtime intact. Use this when telmi OS stays the source of truth and you want the connector to restart its inbox lifecycle cleanly.</p>
                <form method="post">
                    <input type="hidden" name="action" value="reset_inbox_backend">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Reset Local Inbox Backend</button>
                </form>
            </section>

            <section class="card">
                <h2><span class="gradient-text">Factory Reset</span></h2>
                <p class="copy">This removes the live config, lock file, SQLite database, and log. Use it only if you want to start from zero.</p>
                <form method="post">
                    <input type="hidden" name="action" value="factory_reset">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label>
                        Type RESET to confirm
                        <input type="text" name="confirmation" value="" required>
                    </label>
                    <button type="submit">Factory Reset Connector</button>
                </form>
            </section>
        <?php else: ?>
            <section class="card">
                <h2><span class="gradient-text">Next Step</span></h2>
                <p class="copy">The connector runtime is gone now. Open <code>install.php</code> again to create a fresh install.</p>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
