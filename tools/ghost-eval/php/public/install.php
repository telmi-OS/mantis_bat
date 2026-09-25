<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$installer = $services['installer'];
$security = $services['security'];
$config = $services['config'];
$storage = $services['storage'];
$storage->migrate();
$requirements = $installer->requirements();
$locked = is_file($installer->lockPath());
$message = '';
$error = '';
$stage = isset($_SESSION['ghost_eval_install_draft']) ? 'choose_space' : 'credentials';
$spaces = $_SESSION['ghost_eval_install_draft']['spaces'] ?? [];
$urls = [];

if (!isset($_SESSION['ghost_eval_installer_secret'])) {
    $_SESSION['ghost_eval_installer_secret'] = $security->randomToken(12);
}
$installerSecret = (string) $_SESSION['ghost_eval_installer_secret'];

if ($locked && isset($_GET['unlock']) && is_string($_GET['unlock'])) {
    $existing = new MantisBat\GhostEval\Config($installer->configPath());
    if ($security->verifySecret($_GET['unlock'], (string) $existing->get('app.installer_secret_hash', ''))) {
        $_SESSION['ghost_eval_installer_unlocked'] = true;
        header('Location: install.php', true, 302);
        exit;
    }
}
$unlockAllowed = !$locked || (($_SESSION['ghost_eval_installer_unlocked'] ?? false) === true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        ghostEvalCheckCsrf($services);
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($locked && !$unlockAllowed) throw new RuntimeException('Installation is locked. Use the installer unlock URL to change configuration.');
        if (!$installer->requirementsPass()) throw new RuntimeException('The server requirements are not satisfied.');

        if ($action === 'discover') {
            $baseUrl = rtrim(trim((string) ($_POST['app_base_url'] ?? '')), '/');
            $timezone = trim((string) ($_POST['timezone'] ?? 'UTC'));
            $apiBase = rtrim(trim((string) ($_POST['ghost_api_base'] ?? '')), '/');
            $token = trim((string) ($_POST['ghost_api_token'] ?? ''));
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
                throw new RuntimeException('Application base URL must be an absolute HTTPS URL.');
            }
            if (!filter_var($apiBase, FILTER_VALIDATE_URL) || strtolower((string) parse_url($apiBase, PHP_URL_SCHEME)) !== 'https') {
                throw new RuntimeException('Ghost API base must be an absolute HTTPS URL.');
            }
            try {
                new DateTimeZone($timezone);
            } catch (Throwable) {
                throw new RuntimeException('Choose a valid PHP timezone, such as Europe/Berlin or UTC.');
            }
            if ($token === '') throw new RuntimeException('Enter a Ghost JWT.');
            $client = new MantisBat\GhostEval\GhostClient($apiBase, $token);
            $client->probe();
            $spaces = $client->groupSpaces();
            if ($spaces === []) throw new RuntimeException('No writable group Files spaces are available to this Ghost JWT. Check File Explorer access and group membership.');
            $_SESSION['ghost_eval_install_draft'] = [
                'base_url' => $baseUrl, 'timezone' => $timezone, 'api_base' => $apiBase,
                'token' => $token, 'spaces' => $spaces,
            ];
            $stage = 'choose_space';
            $message = 'Ghost API is reachable. Choose the group Files space that will own evaluation sets and reports.';
        } elseif ($action === 'install') {
            $draft = $_SESSION['ghost_eval_install_draft'] ?? null;
            if (!is_array($draft)) throw new RuntimeException('Load Ghost group Files spaces before installing.');
            $spaceId = trim((string) ($_POST['space_id'] ?? ''));
            $space = null;
            foreach (($draft['spaces'] ?? []) as $candidate) {
                if (is_array($candidate) && (string) ($candidate['space_id'] ?? '') === $spaceId) $space = $candidate;
            }
            if (!is_array($space) || !in_array(($space['group_role'] ?? ''), ['owner', 'write'], true)) {
                throw new RuntimeException('Choose a writable group Files space from the discovered list.');
            }
            $client = new MantisBat\GhostEval\GhostClient((string) $draft['api_base'], (string) $draft['token']);
            $client->probe();
            $freshSpaces = $client->groupSpaces();
            $stillAllowed = false;
            foreach ($freshSpaces as $candidate) {
                if (($candidate['space_id'] ?? '') === $spaceId && ($candidate['group_id'] ?? '') === ($space['group_id'] ?? '')) $stillAllowed = true;
            }
            if (!$stillAllowed) throw new RuntimeException('That group Files space is no longer writable by this Ghost. Reload the available spaces.');

            $cronSecret = $security->randomToken(24);
            $accessSecret = $security->randomToken(24);
            $statusSecret = $security->randomToken(24);
            $healthSecret = $security->randomToken(24);
            $configData = [
                'app' => [
                    'installed' => true, 'base_url' => (string) $draft['base_url'], 'timezone' => (string) $draft['timezone'],
                    'version' => '0.1.0', 'cron_secret' => $cronSecret, 'access_secret' => $accessSecret,
                    'status_secret' => $statusSecret, 'health_secret' => $healthSecret,
                    'installer_secret_hash' => $security->hashSecret($installerSecret),
                ],
                'ghost' => [
                    'api_base' => (string) $draft['api_base'], 'api_token' => (string) $draft['token'],
                    'group_id' => (string) ($space['group_id'] ?? ''), 'group_name' => (string) ($space['label'] ?? 'Group'),
                ],
                'files' => ['space_id' => $spaceId, 'group_label' => (string) ($space['label'] ?? 'Group'), 'folder_id' => ''],
                'evaluation' => [
                    'use_rag' => true, 'use_history' => false, 'max_cases' => 40,
                    'judge_rubric' => 'Judge factual support against the supplied memory extract. Mark unsupported claims as fail, a correct refusal as pass for out-of-scope cases, and insufficient evidence as unclear.',
                ],
                'notifications' => ['enabled' => false, 'on_start' => false, 'on_finish' => true, 'on_errors' => true, 'on_p0_failures' => true],
            ];
            $config->write($configData);
            $installer->lock();
            $urls = [
                'App' => (string) $draft['base_url'] . '/index.php?key=' . rawurlencode($accessSecret),
                'Cron' => (string) $draft['base_url'] . '/cron.php?key=' . rawurlencode($cronSecret),
                'Status' => (string) $draft['base_url'] . '/status.php?key=' . rawurlencode($statusSecret),
                'Health' => (string) $draft['base_url'] . '/health.php?key=' . rawurlencode($healthSecret),
                'Maintenance' => (string) $draft['base_url'] . '/maintenance.php?key=' . rawurlencode($statusSecret),
                'Installer unlock secret' => $installerSecret,
            ];
            unset($_SESSION['ghost_eval_install_draft'], $_SESSION['ghost_eval_installer_secret'], $_SESSION['ghost_eval_installer_unlocked']);
            $_SESSION['ghost_eval_installed_output'] = $urls;
            $message = 'Installation complete. Ghost API and writable group Files space validated.';
            $stage = 'complete';
        } else {
            throw new RuntimeException('Unknown installer action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $spaces = $_SESSION['ghost_eval_install_draft']['spaces'] ?? [];
        $stage = is_array($_SESSION['ghost_eval_install_draft'] ?? null) ? 'choose_space' : 'credentials';
    }
}

if (isset($_SESSION['ghost_eval_installed_output']) && is_array($_SESSION['ghost_eval_installed_output'])) {
    $urls = $_SESSION['ghost_eval_installed_output'];
    unset($_SESSION['ghost_eval_installed_output']);
    $stage = 'complete';
}
$csrf = ghostEvalCsrf($services);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ghost Eval Install | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<main class="page-shell">
    <section class="hero">
        <div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Standalone Mantis Bat Tool</p><h1><span class="gradient-text">Ghost Eval</span> Install</h1></div></div>
        <p class="copy">Configure a Ghost, select its writable group Files space, and get private dashboard and cron URLs. Evaluation sets and Markdown reports stay in that Files space.</p>
    </section>

    <?php if ($message !== ''): ?><section class="card"><h2><span class="gradient-text">Setup</span></h2><p><?= ghostEvalH($message) ?></p><?php foreach ($urls as $label => $url): ?><p><strong><?= ghostEvalH($label) ?></strong><br><code><?= ghostEvalH($url) ?></code></p><?php endforeach; ?></section><?php endif; ?>
    <?php if ($error !== ''): ?><section class="card alert-card"><h2>Setup issue</h2><p><?= ghostEvalH($error) ?></p></section><?php endif; ?>

    <div class="card-grid">
        <section class="card"><h2><span class="gradient-text">Requirements</span></h2><ul class="clean-list"><?php foreach ($requirements as $name => $ok): ?><li><img src="assets/mantis-pixel-bullet.svg" alt=""><span><?= ghostEvalH(str_replace('_', ' ', $name)) ?>: <strong class="<?= $ok ? 'status-ok' : 'status-bad' ?>"><?= $ok ? 'ready' : 'missing' ?></strong></span></li><?php endforeach; ?></ul><p class="field-help">Expose only <code>public/</code>. Keep <code>storage/</code> private and writable by PHP.</p></section>
        <section class="card">
            <?php if ($locked && !$unlockAllowed): ?>
                <h2><span class="gradient-text">Installer locked</span></h2><p>Ghost Eval is already installed. The dashboard and cron URLs are available from the original setup output. Use the installer unlock secret to replace this configuration.</p>
            <?php elseif ($stage === 'choose_space' && is_array($_SESSION['ghost_eval_install_draft'] ?? null)): ?>
                <h2><span class="gradient-text">Choose group Files space</span></h2><p class="copy">Only group spaces where this Ghost has write access are shown.</p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="install"><label>Group Files space<select name="space_id" required><option value="">Choose a group</option><?php foreach ($spaces as $space): ?><option value="<?= ghostEvalH((string) ($space['space_id'] ?? '')) ?>"><?= ghostEvalH((string) ($space['label'] ?? 'Group')) ?> · <?= ghostEvalH((string) ($space['group_role'] ?? '')) ?></option><?php endforeach; ?></select><span class="field-help">This group context is also used for challenge, judge, and optional notification chats.</span></label><button type="submit">Install Ghost Eval</button></form>
            <?php elseif ($stage === 'complete'): ?>
                <h2><span class="gradient-text">Installed</span></h2><p>Save the private URLs shown above. Open the App URL to configure evaluation options and choose a JSON suite from the group Files space.</p>
            <?php else: ?>
                <h2><span class="gradient-text">Connect Ghost</span></h2>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="discover">
                    <label>Application base URL<input name="app_base_url" value="<?= ghostEvalH((string) ($_POST['app_base_url'] ?? '')) ?>" placeholder="https://example.com/ghost-eval/public" required></label>
                    <label>Timezone<input name="timezone" value="<?= ghostEvalH((string) ($_POST['timezone'] ?? 'Europe/Berlin')) ?>" required></label>
                    <label>Ghost API base<input name="ghost_api_base" value="<?= ghostEvalH((string) ($_POST['ghost_api_base'] ?? 'https://dev.telmi-ai.com/api/ghost/v2')) ?>" required></label>
                    <label>Ghost JWT<input type="password" name="ghost_api_token" autocomplete="new-password" required><span class="field-help">Stored server-side. Never sent to browser JavaScript.</span></label>
                    <label>Installer unlock secret<input type="password" value="<?= ghostEvalH($installerSecret) ?>" readonly><span class="field-help">A private unlock secret is generated for setup and shown after installation.</span></label>
                    <button type="submit">Check Ghost and find group spaces</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
