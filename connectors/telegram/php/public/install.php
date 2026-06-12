<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$moduleRoot = require __DIR__ . '/_module_root.php';
foreach ([
    'Security',
    'Config',
    'Installer',
    'TelegramClient',
    'GhostClient',
    'Storage',
] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

$security = new MantisBat\Security();
$installer = new MantisBat\Installer($moduleRoot, $security);

$requirements = $installer->requirements();
$locked = is_file($installer->lockPath());
$unlockToken = isset($_GET['unlock']) && is_string($_GET['unlock']) ? $_GET['unlock'] : null;
$csrfToken = $_SESSION['mantis_bat_install_csrf'] ?? $security->randomToken(16);
$_SESSION['mantis_bat_install_csrf'] = $csrfToken;
$message = '';
$pairingCode = '';
$pairingLink = '';
$cronUrl = '';
$statusUrl = '';
$healthUrl = '';
$statusSecret = '';
$healthSecret = '';
$unlockAllowed = false;
$pathChecks = [];

$existingConfig = new MantisBat\Config($installer->configPath());
if ($locked) {
    $unlockAllowed = $security->verifySecret($unlockToken, (string) $existingConfig->get('app.installer_secret_hash', ''));
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if ($host !== '' && $requestUri !== '') {
    $pathChecks = $installer->protectedPathChecks($scheme . '://' . $host . $requestUri);
}

$defaults = [
    'app_base_url' => (string) ($_POST['app_base_url'] ?? ''),
    'app_timezone' => (string) ($_POST['app_timezone'] ?? 'Europe/Berlin'),
    'app_cron_secret' => (string) ($_POST['app_cron_secret'] ?? $security->randomToken(12)),
    'telegram_bot_token' => (string) ($_POST['telegram_bot_token'] ?? ''),
    'telegram_webhook_secret' => (string) ($_POST['telegram_webhook_secret'] ?? $security->randomToken(12)),
    'ghost_api_base' => (string) ($_POST['ghost_api_base'] ?? 'https://dev.telmi-ai.com/api/ghost/v2'),
    'ghost_api_token' => (string) ($_POST['ghost_api_token'] ?? ''),
    'ghost_default_group_id' => (string) ($_POST['ghost_default_group_id'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!$security->constantTimeEquals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid installer session token. Reload the page and try again.');
        }

        if ($locked && !$unlockAllowed) {
            throw new RuntimeException('Installer is locked. Provide a valid unlock token in the URL to overwrite the configuration.');
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

        $telegram = new MantisBat\TelegramClient($defaults['telegram_bot_token']);
        $telegramInfo = $telegram->getMe();
        $botUsername = (string) ($telegramInfo['result']['username'] ?? '');
        if ($botUsername === '') {
            throw new RuntimeException('Telegram validation failed.');
        }

        $config = [
            'app' => [
                'installed' => true,
                'base_url' => rtrim($defaults['app_base_url'], '/'),
                'timezone' => $defaults['app_timezone'],
                'log_level' => 'info',
                'cron_secret' => $defaults['app_cron_secret'],
                'status_secret' => $security->randomToken(12),
                'health_secret' => $security->randomToken(12),
                'installer_secret_hash' => $security->hashSecret((string) ($_POST['installer_secret'] ?? $security->randomToken(10))),
                'version' => '0.1.0',
            ],
            'telegram' => [
                'bot_token' => $defaults['telegram_bot_token'],
                'webhook_secret' => $defaults['telegram_webhook_secret'],
                'owner_chat_id' => null,
                'owner_username' => null,
            ],
            'ghost' => [
                'api_base' => rtrim($defaults['ghost_api_base'], '/'),
                'api_token' => $defaults['ghost_api_token'],
                'default_group_id' => $defaults['ghost_default_group_id'],
                'ghost_id_mode' => 'token',
                'paths' => [
                    'chat' => '/chat',
                    'inbox' => '/inbox',
                    'inbox_ack' => '/inbox/ack',
                    'memory_upsert' => '/memory/upsert',
                    'memory_list' => '/memory/list',
                    'memory_delete' => '/memory/delete',
                    'memory_search' => '/memory/search',
                    'settings' => '/settings',
                ],
            ],
            'commands' => [
                'memory_up_prefix' => 'bat_memory_up:',
                'memory_search_prefix' => 'bat_memory_search:',
                'memory_list_command' => 'bat_memory_list',
                'memory_delete_prefix' => 'bat_memory_delete:',
                'help_command' => 'bat_help',
                'status_command' => 'bat_status',
            ],
            'limits' => [
                'telegram_max_message_chars' => 3900,
                'max_inbound_message_chars' => 20000,
                'max_memory_upload_chars' => 50000,
                'cron_batch_size' => 20,
            ],
        ];

        $installer->writeConfig($config);

        $runtimeConfig = new MantisBat\Config($installer->configPath());
        $ghost = new MantisBat\GhostClient($runtimeConfig);
        $ghost->readSettings();

        $pairingCode = strtoupper(substr($security->randomToken(8), 0, 6));
        $storage->createPairingCode($pairingCode, time() + 600);

        $webhookUrl = rtrim($defaults['app_base_url'], '/') . '/webhook.php';
        $telegram->setWebhook($webhookUrl, $defaults['telegram_webhook_secret']);
        $installer->lock($security->randomToken(8));

        $pairingLink = sprintf('https://t.me/%s?start=%s', $botUsername, $pairingCode);
        $cronUrl = rtrim($defaults['app_base_url'], '/') . '/cron.php?key=' . rawurlencode($defaults['app_cron_secret']);
        $statusSecret = (string) $config['app']['status_secret'];
        $healthSecret = (string) $config['app']['health_secret'];
        $statusUrl = rtrim($defaults['app_base_url'], '/') . '/status.php?key=' . rawurlencode($statusSecret);
        $healthUrl = rtrim($defaults['app_base_url'], '/') . '/health.php?key=' . rawurlencode($healthSecret);
        $message = "Install complete.\nTelegram validated.\nGhost validated.\nWebhook registered.";
    } catch (Throwable $exception) {
        error_log('[Mantis Bat installer] ' . $exception->getMessage());
        $message = $exception->getMessage();
    }
}

require $moduleRoot . '/templates/install.html.php';
