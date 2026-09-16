<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
$config = $services['config'];
$auth = $services['auth'];
if (!$config->installed()) { header('Location: install.php'); exit; }
if ($auth->sessionKey() === null) { header('Location: login.php'); exit; }
$key = $auth->requireSessionKey();
$storage = new MantisBat\GoogleCalendar\Storage($moduleRoot . '/storage/google_calendar.sqlite', $key);
$storage->migrate();
$csrf = $_SESSION['gcc_dashboard_csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['gcc_dashboard_csrf'] = $csrf;
$message = '';
$error = '';
$google = null;
$service = null;
try {
    $google = new MantisBat\GoogleCalendar\GoogleClient(
        (string) $storage->get('google_client_id', ''),
        (string) $storage->get('google_client_secret', ''),
        rtrim((string) $config->get('app.base_url', ''), '/') . '/oauth_callback.php'
    );
    $ghost = new MantisBat\GoogleCalendar\GhostClient(
        (string) $storage->get('ghost_api_base', ''),
        (string) $storage->get('ghost_api_token', '')
    );
    $prompts = new MantisBat\GoogleCalendar\PromptBuilder($moduleRoot . '/templates/sync-prompt.txt');
    $service = new MantisBat\GoogleCalendar\SyncService($config, $storage, $google, $ghost, $prompts);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Invalid dashboard session token.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $service->saveSettings([
                'group_name' => (string) ($_POST['group_name'] ?? ''),
                'calendar_id' => (string) ($_POST['calendar_id'] ?? ''),
                'calendar_name' => (string) ($_POST['calendar_name'] ?? ''),
                'google_client_id' => (string) ($_POST['google_client_id'] ?? '') !== '' ? (string) $_POST['google_client_id'] : (string) $storage->get('google_client_id', ''),
                'google_client_secret' => (string) ($_POST['google_client_secret'] ?? '') !== '' ? (string) $_POST['google_client_secret'] : (string) $storage->get('google_client_secret', ''),
                'ghost_api_base' => (string) ($_POST['ghost_api_base'] ?? ''),
                'ghost_api_token' => (string) ($_POST['ghost_api_token'] ?? '') !== '' ? (string) $_POST['ghost_api_token'] : (string) $storage->get('ghost_api_token', ''),
                'interval_minutes' => (int) ($_POST['interval_minutes'] ?? 15),
                'prompt_template' => (string) ($_POST['prompt_template'] ?? ''),
            ]);
            $message = 'Settings saved.';
        } elseif ($action === 'run') {
            $lock = fopen($moduleRoot . '/storage/sync.lock', 'c+');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('A sync is already running.');
            try {
                $result = $service->run(true);
                $message = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } elseif ($action === 'pause') {
            $storage->set('paused', $storage->get('paused', '0') === '1' ? '0' : '1');
            $message = $storage->get('paused', '0') === '1' ? 'Synchronization paused.' : 'Synchronization resumed.';
        } elseif ($action === 'password') {
            $auth->changePassword((string) ($_POST['new_password'] ?? ''));
            $message = 'Application password changed.';
        }
    }
} catch (Throwable $e) { $error = $e->getMessage(); }

$calendarOptions = [];
if ($service !== null && (string) $storage->get('google_refresh_token', '') !== '') {
    try { $calendarOptions = $google->listCalendars($service->accessToken()); } catch (Throwable $e) { if ($error === '') $error = $e->getMessage(); }
}
$summary = $storage->summary();
$runs = $storage->recentRuns();
$selectedCalendar = (string) $storage->get('calendar_id', '');
$selectedCalendarName = (string) $storage->get('calendar_name', '');
$promptTemplate = (string) ($storage->get('prompt_template', '') ?: (new MantisBat\GoogleCalendar\PromptBuilder($moduleRoot . '/templates/sync-prompt.txt'))->defaultTemplate());
$paused = $storage->get('paused', '0') === '1';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google Calendar Connector</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="shell">
<header class="topbar"><div><h1>Google Calendar <span>Connector</span></h1><p class="muted">Calendar is the source of truth. The configured Ghost manages Board schedules.</p></div><a href="logout.php">Log out</a></header>
<?php if ($message !== ''): ?><div class="alert success"><pre><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></pre></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="stats"><div><strong><?= (int) $summary['events'] ?></strong><small>stored occurrences</small></div><div><strong><?= (int) $summary['pending'] ?></strong><small>pending changes</small></div><div><strong><?= $paused ? 'Paused' : 'Active' ?></strong><small>sync state</small></div></section>
<section class="grid">
<div class="card"><h2>Google Calendar</h2><?php if ((string) $storage->get('google_refresh_token', '') === ''): ?><p>Connect the Google account, then choose exactly one calendar.</p><a class="button" href="oauth_start.php">Connect Google</a><?php else: ?><p class="ok">Google account connected.</p><p><a href="oauth_start.php">Reconnect Google</a></p><?php endif; ?>
<label>Selected calendar<select id="calendar-select" name="calendar_id" form="settings-form"><option value="">Choose one calendar</option><?php if ($selectedCalendar !== '' && $calendarOptions === []): ?><option value="<?= htmlspecialchars($selectedCalendar, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($selectedCalendarName !== '' ? $selectedCalendarName : $selectedCalendar, ENT_QUOTES, 'UTF-8') ?></option><?php endif; ?><?php foreach ($calendarOptions as $calendar): ?><option value="<?= htmlspecialchars((string) ($calendar['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars((string) ($calendar['summary'] ?? $calendar['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($calendar['id'] ?? '') === $selectedCalendar ? 'selected' : '' ?>><?= htmlspecialchars((string) ($calendar['summary'] ?? $calendar['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
</div>
<div class="card"><h2>Ghost and group</h2><label>Ghost API base<input id="ghost_api_base" name="ghost_api_base" form="settings-form" value="<?= htmlspecialchars((string) $storage->get('ghost_api_base', 'https://dev.telmi-ai.com/api/ghost/v2'), ENT_QUOTES, 'UTF-8') ?>" required></label><label>Ghost JWT<input id="ghost_api_token" name="ghost_api_token" form="settings-form" type="password" placeholder="Leave blank to keep current"></label><label>Group name<input id="group_name" name="group_name" form="settings-form" value="<?= htmlspecialchars((string) $storage->get('group_name', ''), ENT_QUOTES, 'UTF-8') ?>" required><small>Inserted exactly as entered in natural-language messages.</small></label></div>
</section>
<form id="settings-form" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="calendar_name" id="calendar_name" value="<?= htmlspecialchars($selectedCalendarName, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="google_client_id" value="<?= htmlspecialchars((string) $storage->get('google_client_id', ''), ENT_QUOTES, 'UTF-8') ?>"><label>Sync interval<select name="interval_minutes"><option value="15" <?= $storage->get('interval_minutes', '15') === '15' ? 'selected' : '' ?>>15 minutes</option><option value="30" <?= $storage->get('interval_minutes', '15') === '30' ? 'selected' : '' ?>>30 minutes</option><option value="60" <?= $storage->get('interval_minutes', '15') === '60' ? 'selected' : '' ?>>1 hour</option><option value="180" <?= $storage->get('interval_minutes', '15') === '180' ? 'selected' : '' ?>>3 hours</option><option value="720" <?= $storage->get('interval_minutes', '15') === '720' ? 'selected' : '' ?>>12 hours</option></select></label><label>Google OAuth client secret<input type="password" name="google_client_secret" placeholder="Leave blank to keep current"></label><label>Sync prompt template<textarea name="prompt_template" rows="11"><?= htmlspecialchars($promptTemplate, ENT_QUOTES, 'UTF-8') ?></textarea><small>Placeholders: {{group_name}}, {{submission_id}}, {{from}}, {{to}}, {{items}}</small></label><button type="submit">Save settings</button></form>
<section class="actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="run"><button type="submit">Run sync now</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="pause"><button type="submit"><?= $paused ? 'Resume sync' : 'Pause sync' ?></button></form></section>
<section class="card"><h2>Recent sync runs</h2><?php if ($runs === []): ?><p class="muted">No sync runs yet.</p><?php else: ?><div class="runs"><?php foreach ($runs as $run): ?><article><strong><?= htmlspecialchars((string) $run['status'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= date('Y-m-d H:i:s', (int) $run['created_at']) ?></span><span><?= (int) $run['item_count'] ?> items</span><?php if ((string) ($run['error'] ?? '') !== ''): ?><pre><?= htmlspecialchars((string) $run['error'], ENT_QUOTES, 'UTF-8') ?></pre><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="card"><h2>Change application password</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="password"><label>New password<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><button type="submit">Change password</button></form></section>
<p class="muted">The cron URL is generated at install time. Schedule it no more frequently than every 15 minutes.</p>
</main><script>
const select=document.getElementById('calendar-select'); select?.addEventListener('change',()=>{const option=select.options[select.selectedIndex];document.getElementById('calendar_name').value=option.dataset.name||option.textContent||'';});
</script></body></html>
