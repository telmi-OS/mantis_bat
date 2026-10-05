<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
if (!$config->isInstalled()) {
    header('Location: install.php', true, 302);
    exit;
}
nextcloudTalkRequireAccess($services);
if (isset($_GET['key'])) {
    header('Location: index.php', true, 302);
    exit;
}

$apiUrl = rtrim((string) $config->get('app.base_url'), '/') . '/api/meet/create';
$apiKey = (string) $config->get('api.auth_key', '');
$headers = json_encode(['X-Auth' => $apiKey], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
$template = json_encode(['vars' => ['meeting_name' => '{{meeting_name}}']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
$description = 'Create a new Nextcloud Talk meeting link. Use this when the user asks to create, start, or generate a meeting or call link. Provide a short descriptive meeting name. The endpoint returns JSON containing meeting_url. Return the meeting_url to the user.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nextcloud Talk Connect | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
    <style>
        .preset-block { position: relative; margin: 12px 0 22px; }
        .preset-block pre { overflow-x: auto; padding: 18px; padding-top: 48px; border: 1px solid var(--telmi-border); border-radius: 14px; background: rgba(0,0,0,.34); white-space: pre-wrap; overflow-wrap: anywhere; }
        .preset-block button { position: absolute; top: 8px; right: 8px; padding: 7px 12px; font-size: .82rem; }
        .preset-url { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main class="page-shell">
    <section class="hero">
        <div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Protected Mantis Bat Tool</p><h1><span class="gradient-text">Nextcloud Talk Connect</span></h1></div></div>
        <p class="copy">Create public Nextcloud Talk meeting links through a small Ghost preset. Same-name requests reuse the meeting for five minutes.</p>
        <p class="copy"><strong>Nextcloud:</strong> <?= nextcloudTalkH((string) $config->get('nextcloud.base_url')) ?> · <strong>Default name:</strong> <?= nextcloudTalkH((string) $config->get('meeting.default_name')) ?></p>
    </section>

    <section class="card">
        <h2><span class="gradient-text">Ghost preset</span></h2>
        <p class="copy">Copy each value into the Ghost's GET request preset. The X-Auth key is private; rotate it by reopening the installer with its unlock URL.</p>
        <h3>URL</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-url">Copy</button><pre id="preset-url"><?= nextcloudTalkH($apiUrl) ?>?name={{meeting_name}}</pre></div>
        <h3>Method</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-method">Copy</button><pre id="preset-method">GET</pre></div>
        <h3>Headers JSON</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-headers">Copy</button><pre id="preset-headers"><?= nextcloudTalkH($headers) ?></pre></div>
        <h3>JSON Template</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-template">Copy</button><pre id="preset-template"><?= nextcloudTalkH($template) ?></pre></div>
        <h3>Description</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-description">Copy</button><pre id="preset-description"><?= nextcloudTalkH($description) ?></pre></div>
        <p class="field-help">The endpoint normalizes whitespace, trims and shortens long names, and uses the configured default when the name is empty. Repeated requests with the same name, ignoring capitalization, return the same meeting URL and token for five minutes.</p>
    </section>

    <section class="card">
        <h2><span class="gradient-text">Operations</span></h2>
        <p><a href="status.php?key=<?= rawurlencode((string) $config->get('app.status_secret')) ?>">Status</a> · <a href="health.php?key=<?= rawurlencode((string) $config->get('app.status_secret')) ?>">Health</a> · <a href="maintenance.php?key=<?= rawurlencode((string) $config->get('app.status_secret')) ?>">Maintenance</a></p>
    </section>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
    const target = document.getElementById(button.dataset.copy);
    if (!target) return;
    try {
        await navigator.clipboard.writeText(target.textContent.trim());
        const old = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = old; }, 1400);
    } catch (_) {
        button.textContent = 'Select and copy';
    }
}));
</script>
</body>
</html>
