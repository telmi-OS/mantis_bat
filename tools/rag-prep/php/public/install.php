<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$moduleRoot = dirname(__DIR__);
foreach ([
    'Security',
    'RuntimeConfig',
    'Installer',
    'GhostClient',
    'Storage',
    'Logger',
] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

$security = new MantisBat\Security();
$installer = new MantisBat\Installer($moduleRoot, $security);
$requirements = $installer->requirements();
$locked = is_file($installer->lockPath());
$unlockToken = isset($_GET['unlock']) && is_string($_GET['unlock']) ? $_GET['unlock'] : null;
$csrfToken = $_SESSION['mantis_bat_rag_prep_install_csrf'] ?? $security->randomToken(16);
$_SESSION['mantis_bat_rag_prep_install_csrf'] = $csrfToken;
$message = '';
$cronUrl = '';
$statusUrl = '';
$healthUrl = '';
$maintenanceUrl = '';
$appUrl = '';
$accessSecret = '';
$unlockAllowed = false;
$configWritten = false;

$existingConfig = new MantisBat\RuntimeConfig($installer->configPath());
if ($locked) {
    $unlockAllowed = $security->verifySecret($unlockToken, (string) $existingConfig->get('app.installer_secret_hash', ''));
}

$defaults = [
    'app_base_url' => (string) ($_POST['app_base_url'] ?? ''),
    'app_timezone' => (string) ($_POST['app_timezone'] ?? 'Europe/Berlin'),
    'app_cron_secret' => (string) ($_POST['app_cron_secret'] ?? $security->randomToken(12)),
    'ghost_api_base' => (string) ($_POST['ghost_api_base'] ?? 'https://dev.telmi-ai.com/api/ghost/v2'),
    'ghost_api_token' => (string) ($_POST['ghost_api_token'] ?? ''),
    'installer_secret' => (string) ($_POST['installer_secret'] ?? $security->randomToken(10)),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!$security->constantTimeEquals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid installer session token. Reload the page and try again.');
        }

        if ($locked && !$unlockAllowed) {
            throw new RuntimeException('Installer is locked. Provide a valid unlock token in the URL to overwrite this configuration.');
        }

        if (!$installer->allRequirementsPass()) {
            throw new RuntimeException('Server requirements are not satisfied.');
        }

        $storage = new MantisBat\Storage($installer->databasePath());
        $storage->migrate();
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($storage->hitRateLimit('install_ip', $remoteIp, 12, 900)) {
            throw new RuntimeException('Too many install attempts. Wait and try again.');
        }

        $baseUrl = rtrim(trim($defaults['app_base_url']), '/');
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('App base URL must be a valid absolute URL.');
        }

        $accessSecret = $security->randomToken(12);
        $config = [
            'app' => [
                'installed' => true,
                'base_url' => $baseUrl,
                'timezone' => $defaults['app_timezone'],
                'log_level' => 'info',
                'cron_secret' => $defaults['app_cron_secret'],
                'status_secret' => $security->randomToken(12),
                'health_secret' => $security->randomToken(12),
                'access_secret' => $accessSecret,
                'installer_secret_hash' => $security->hashSecret($defaults['installer_secret']),
                'version' => '0.1.0',
            ],
            'ghost' => [
                'api_base' => rtrim(trim($defaults['ghost_api_base']), '/'),
                'api_token' => trim($defaults['ghost_api_token']),
                'paths' => [
                    'chat' => '/chat',
                    'settings' => '/settings',
                ],
            ],
            'limits' => [
                'max_file_size_mb' => 15,
                'max_job_size_mb' => 20,
                'max_files_per_job' => 5,
                'max_source_characters' => 120000,
                'worker_jobs_per_run' => 1,
            ],
            'features' => [
                'pdf_support' => true,
                'docx_support' => true,
                'txt_support' => true,
            ],
        ];

        $installer->writeConfig($config);
        $configWritten = true;

        $runtimeConfig = new MantisBat\RuntimeConfig($installer->configPath());
        $logger = new MantisBat\Logger($security, $storage, $installer->logPath(), [(string) $runtimeConfig->get('ghost.api_token', '')]);
        $ghost = new MantisBat\GhostClient($runtimeConfig, $logger);
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

        $installer->lock();
        $appUrl = $baseUrl . '/index.php?key=' . rawurlencode($accessSecret);
        $cronUrl = $baseUrl . '/cron.php?key=' . rawurlencode((string) $config['app']['cron_secret']);
        $statusUrl = $baseUrl . '/status.php?key=' . rawurlencode((string) $config['app']['status_secret']);
        $healthUrl = $baseUrl . '/health.php?key=' . rawurlencode((string) $config['app']['health_secret']);
        $maintenanceUrl = $baseUrl . '/maintenance.php?key=' . rawurlencode((string) $config['app']['status_secret']);
        $message = "Install complete.\nGhost API validated.\nRAG prep tool is ready.";
    } catch (Throwable $exception) {
        if ($configWritten && !$locked) {
            @unlink($installer->configPath());
            @unlink($installer->lockPath());
        }
        error_log('[Mantis Bat rag-prep installer] ' . $exception->getMessage());
        $message = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS RAG Prep Install | Mantis Bat</title>
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
                <p class="eyebrow">Teleport AI Official Tool Repo</p>
                <h1><span class="gradient-text">RAG Prep</span> Install</h1>
            </div>
        </div>
        <div class="hero-grid">
            <div class="copy">
                <p>Prepare semantic telmi OS-ready memory chunks from uploaded PDF, TXT, and DOCX documents. This tool extracts the text locally and lets your Ghost produce the final retrieval-optimized artifact.</p>
            </div>
            <div class="hero-shot">
                <img src="assets/telmi-os-desktop.png" alt="telmi OS desktop">
            </div>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <section class="card">
            <h2><span class="gradient-text">Installer Output</span></h2>
            <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
            <?php if ($appUrl !== ''): ?>
                <p><strong>App URL</strong></p>
                <pre><?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Cron URL</strong></p>
                <pre><?= htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Status URL</strong></p>
                <pre><?= htmlspecialchars($statusUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Health URL</strong></p>
                <pre><?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Maintenance URL</strong></p>
                <pre><?= htmlspecialchars($maintenanceUrl, ENT_QUOTES, 'UTF-8') ?></pre>
                <p><strong>Installer unlock secret</strong></p>
                <pre><?= htmlspecialchars($defaults['installer_secret'], ENT_QUOTES, 'UTF-8') ?></pre>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card-grid">
        <section class="card">
            <h2><span class="gradient-text">Requirements</span></h2>
            <ul>
                <?php foreach ($requirements as $name => $passed): ?>
                    <li><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>: <?= $passed ? 'ok' : 'missing' ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card">
            <h2><span class="gradient-text">Install</span></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    App Base URL
                    <input type="text" name="app_base_url" value="<?= htmlspecialchars($defaults['app_base_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://example.com/tools/rag-prep/public" required>
                </label>
                <label>
                    Timezone
                    <input type="text" name="app_timezone" value="<?= htmlspecialchars($defaults['app_timezone'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>
                    Cron Secret
                    <input type="text" name="app_cron_secret" value="<?= htmlspecialchars($defaults['app_cron_secret'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>
                    Ghost API Base
                    <input type="text" name="ghost_api_base" value="<?= htmlspecialchars($defaults['ghost_api_base'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>
                    Ghost JWT
                    <input type="text" name="ghost_api_token" value="<?= htmlspecialchars($defaults['ghost_api_token'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>
                    Installer Unlock Secret
                    <input type="text" name="installer_secret" value="<?= htmlspecialchars($defaults['installer_secret'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <button type="submit">Install RAG Prep Tool</button>
            </form>
        </section>
    </section>
</div>
</body>
</html>
