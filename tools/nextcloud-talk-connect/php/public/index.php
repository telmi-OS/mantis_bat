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

$historyCutoff = time() - (7 * 24 * 60 * 60);
$services['storage']->purgeMeetingHistory($historyCutoff);
$meetingHistoryCount = $services['storage']->meetingHistoryCount($historyCutoff);
$meetingHistory = $services['storage']->recentMeetingHistory($historyCutoff, 500);
$apiUrl = rtrim((string) $config->get('app.base_url'), '/') . '/api/meet/create.php';
$apiKey = (string) $config->get('api.auth_key', '');
$headers = json_encode(['X-Auth' => $apiKey, 'X-Meeting-Name' => '{{meeting_name}}'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
$description = 'Create a new Nextcloud Talk meeting link. Use this when the user asks to create, start, or generate a meeting or call link. Set meeting_name to a short descriptive name based on the user request; Amygdala sends it in the X-Meeting-Name header. The endpoint returns JSON containing meeting_url. Return the meeting_url to the user.';
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
        .history-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .history-count { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid rgba(40, 215, 255, .28); border-radius: 999px; color: #c9f7ff; background: rgba(6, 182, 212, .1); white-space: nowrap; }
        .history-count::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #28d7ff; box-shadow: 0 0 12px rgba(40, 215, 255, .8); }
        .meeting-list { display: grid; gap: 12px; margin-top: 18px; }
        .meeting-item { padding: 18px; border: 1px solid var(--telmi-border); border-radius: 18px; background: linear-gradient(130deg, rgba(139, 92, 246, .1), rgba(6, 182, 212, .06)); }
        .meeting-item-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
        .meeting-item h3 { margin: 0 0 5px; font-size: 1.08rem; }
        .meeting-item time { color: var(--telmi-muted); font-size: .9rem; }
        .meeting-link { display: flex; align-items: center; gap: 10px; color: #bcefff; overflow-wrap: anywhere; text-decoration: none; }
        .meeting-link:hover { color: white; text-decoration: underline; }
        .meeting-link-mark { display: inline-grid; place-items: center; width: 28px; height: 28px; flex: 0 0 auto; border-radius: 50%; background: rgba(40, 215, 255, .16); }
        .meeting-url { display: block; margin-top: 8px; color: var(--telmi-muted); font-family: "Share Tech Mono", monospace; font-size: .82rem; overflow-wrap: anywhere; }
        .empty-history { padding: 26px 18px; border: 1px dashed rgba(255,255,255,.22); border-radius: 18px; text-align: center; color: var(--telmi-muted); }
        @media (max-width: 600px) { .history-heading, .meeting-item-head { align-items: flex-start; flex-direction: column; } }
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
        <div class="history-heading"><div><h2><span class="gradient-text">Recent meetings</span></h2><p class="copy">Meeting links created in the last seven days. Times are shown in UTC.</p></div><span class="history-count"><?= (int) $meetingHistoryCount ?> <?= $meetingHistoryCount === 1 ? 'meeting' : 'meetings' ?></span></div>
        <?php if ($meetingHistory === []): ?>
            <div class="empty-history"><p>No meetings created in the last seven days.</p><p>New meeting links will appear here once the Ghost creates one.</p></div>
        <?php else: ?>
            <div class="meeting-list">
                <?php foreach ($meetingHistory as $meeting): ?>
                    <?php $createdAt = (int) $meeting['created_at']; $meetingUrl = (string) $meeting['meeting_url']; ?>
                    <article class="meeting-item">
                        <div class="meeting-item-head"><div><h3><?= nextcloudTalkH((string) $meeting['meeting_name']) ?></h3><time datetime="<?= nextcloudTalkH(date(DATE_ATOM, $createdAt)) ?>"><?= nextcloudTalkH(date('D, M j · H:i', $createdAt)) ?></time></div></div>
                        <a class="meeting-link" href="<?= nextcloudTalkH($meetingUrl) ?>" target="_blank" rel="noopener noreferrer"><span class="meeting-link-mark" aria-hidden="true">↗</span><span>Join meeting<span class="meeting-url"><?= nextcloudTalkH($meetingUrl) ?></span></span></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($meetingHistoryCount > count($meetingHistory)): ?><p class="field-help">Showing the 500 most recent meetings out of <?= (int) $meetingHistoryCount ?> from the last seven days.</p><?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2><span class="gradient-text">Ghost preset</span></h2>
        <p class="copy">Copy each value into the Ghost's GET request preset. The X-Auth key is private; rotate it by reopening the installer with its unlock URL.</p>
        <h3>URL</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-url">Copy</button><pre id="preset-url"><?= nextcloudTalkH($apiUrl) ?></pre></div>
        <h3>Method</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-method">Copy</button><pre id="preset-method">GET</pre></div>
        <h3>Headers JSON</h3><div class="preset-block"><button type="button" class="button-secondary" data-copy="preset-headers">Copy</button><pre id="preset-headers"><?= nextcloudTalkH($headers) ?></pre></div>
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
