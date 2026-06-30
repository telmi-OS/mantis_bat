<?php

declare(strict_types=1);

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

$expectedKey = (string) $config->get('app.status_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;

if ($expectedKey !== '' && !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$message = '';
$pairingCode = '';
$pairingLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$config->isInstalled()) {
            throw new RuntimeException('Connector is not installed.');
        }

        $bot = $telegram->getMe();
        $botUsername = (string) ($bot['result']['username'] ?? '');
        if ($botUsername === '') {
            throw new RuntimeException('Could not read Telegram bot username.');
        }

        $pairingCode = strtoupper(substr($security->randomToken(8), 0, 6));
        $storage->createPairingCode($pairingCode, time() + 86400);
        $pairingLink = sprintf('https://t.me/%s?start=%s', $botUsername, $pairingCode);
        $message = 'New pairing code created. It stays valid for 24 hours or until it is used once.';
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
    <title>Mantis Bat Pairing</title>
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
                <h1><span class="gradient-text">Telegram Pairing</span> Recovery</h1>
            </div>
        </div>
    </section>

    <div class="card-grid">
        <section class="card">
            <h2><span class="gradient-text">Create Fresh Pairing Code</span></h2>
            <p class="copy">Use this page only if install succeeded but the Telegram account is still not paired. It creates a new single-use pairing code without reinstalling the connector.</p>
            <form method="post">
                <button type="submit">Generate New Pairing Code</button>
            </form>
        </section>

        <?php if ($message !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Status</span></h2>
                <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>

        <?php if ($pairingCode !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Use This Code</span></h2>
                <p><strong>Pairing code</strong></p>
                <pre><?= htmlspecialchars($pairingCode, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Telegram deep link</strong></p>
                <pre><?= htmlspecialchars($pairingLink, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
