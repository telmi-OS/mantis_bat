<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS Connector Install | Mantis Bat</title>
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
                <h1><span class="gradient-text">telmi OS</span> Connector Install</h1>
            </div>
        </div>
        <div class="hero-grid">
            <div class="copy">
                <p>Mantis Bat is the channel-side connector layer for <strong>telmi OS</strong>. This installer connects your own Telegram bot to your own Ghost while keeping runtime configuration private on your hosting account.</p>
                <p>Managed installs and hosted runtime can also be handled through telmi OS directly. This module exists for users, builders, and community deployers who want the self-hosted path under their own control.</p>
                <div class="card-grid" style="margin-top:18px;">
                    <div class="status-pill">Private bot ownership</div>
                    <div class="status-pill">Ghost API v2 runtime</div>
                    <div class="status-pill">Single-owner Telegram pairing</div>
                </div>
            </div>
            <div class="hero-shot">
                <img src="assets/telmi-os-desktop.png" alt="telmi OS desktop">
            </div>
        </div>
    </section>

    <div class="card-grid">
        <section class="card">
            <h2><span class="gradient-text">Server Readiness</span></h2>
            <ul class="clean-list">
                <?php foreach ($requirements as $name => $result): ?>
                    <li>
                        <img src="assets/mantis-pixel-bullet.svg" alt="">
                        <span>
                            <strong><?= htmlspecialchars(str_replace('_', ' ', $name), ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <span class="<?= $result ? 'status-ok' : 'status-bad' ?>"><?= $result ? 'ready' : 'missing or blocked' ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($pathChecks !== []): ?>
            <section class="card">
                <h2><span class="gradient-text">Public Exposure Check</span></h2>
                <p class="copy">The installer tested a few non-public paths from this server. Continue only if they are blocked.</p>
                <ul class="clean-list">
                    <?php foreach ($pathChecks as $check): ?>
                        <li>
                            <img src="assets/mantis-pixel-bullet.svg" alt="">
                            <span>
                                <strong><?= htmlspecialchars($check['url'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <?php if ($check['safe'] === true): ?>
                                    <span class="status-ok">HTTP <?= (int) $check['status'] ?>. <?= htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php elseif ($check['safe'] === false): ?>
                                    <span class="status-bad">HTTP <?= (int) $check['status'] ?>. <?= htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="field-help"><?= htmlspecialchars($check['message'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($locked && !$unlockAllowed): ?>
            <section class="card">
                <h2><span class="gradient-text">Installer Locked</span></h2>
                <p class="copy">This connector is already installed. To intentionally overwrite the live configuration, reopen this page with your private unlock token in the URL and resubmit the form.</p>
                <pre>install.php?unlock=YOUR_PRIVATE_INSTALLER_UNLOCK_SECRET</pre>
            </section>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Install Status</span></h2>
                <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>

        <?php if (!$locked || $unlockAllowed): ?>
            <section class="card">
                <h2><span class="gradient-text">Connector Configuration</span></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label>
                        Base URL
                        <span class="field-help">Public URL to this connector's <code>public/</code> directory. Example: <code>https://example.com/mantis-bat/public</code></span>
                        <input type="text" name="app_base_url" value="<?= htmlspecialchars($defaults['app_base_url'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Timezone
                        <span class="field-help">Deployment timezone for logs, pairing windows, and cron-related timestamps.</span>
                        <input type="text" name="app_timezone" value="<?= htmlspecialchars($defaults['app_timezone'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Cron Secret
                        <span class="field-help">Protects the HTTP cron endpoint. Keep this secret private after install.</span>
                        <input type="text" name="app_cron_secret" value="<?= htmlspecialchars($defaults['app_cron_secret'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Installer Unlock Secret
                        <span class="field-help">Required only if you want to unlock and overwrite this install later.</span>
                        <input type="text" name="installer_secret" value="">
                    </label>
                    <label>
                        Telegram Bot Token
                        <span class="field-help">Copy this directly from BotFather.</span>
                        <input type="text" name="telegram_bot_token" value="<?= htmlspecialchars($defaults['telegram_bot_token'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Telegram Webhook Secret
                        <span class="field-help">Telegram sends this value back in the webhook header. Do not share it.</span>
                        <input type="text" name="telegram_webhook_secret" value="<?= htmlspecialchars($defaults['telegram_webhook_secret'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Ghost API Base
                        <span class="field-help">Default public Ghost API v2 base for telmi OS.</span>
                        <input type="text" name="ghost_api_base" value="<?= htmlspecialchars($defaults['ghost_api_base'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Ghost JWT
                        <span class="field-help">Private bearer token for the selected Ghost.</span>
                        <input type="text" name="ghost_api_token" value="<?= htmlspecialchars($defaults['ghost_api_token'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                    <label>
                        Default Group ID
                        <span class="field-help">Optional. Leave empty if you want Ghost-default context.</span>
                        <input type="text" name="ghost_default_group_id" value="<?= htmlspecialchars($defaults['ghost_default_group_id'], ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit">Install Connector</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($pairingCode !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Save These Private URLs</span></h2>
                <p class="copy">The installer has finished. Save everything below in a password manager or private team vault before closing this page.</p>
                <p><strong>Pairing code</strong></p>
                <pre><?= htmlspecialchars($pairingCode, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Telegram deep link</strong></p>
                <pre><?= htmlspecialchars($pairingLink, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Cron URL</strong></p>
                <pre><?= htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Status URL</strong></p>
                <pre><?= htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Health URL</strong></p>
                <pre><?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
