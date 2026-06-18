<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class RuntimeConfig
{
    private string $configPath;
    private array $data;
    private ?string $buildFingerprint = null;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->data = $this->load();
        date_default_timezone_set((string) $this->get('app.timezone', 'UTC'));
    }

    public function exists(): bool
    {
        return is_file($this->configPath);
    }

    public function getPath(): string
    {
        return $this->configPath;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException("Missing required config key: {$key}");
        }

        return $value;
    }

    public function isInstalled(): bool
    {
        return (bool) $this->get('app.installed', false);
    }

    public function publicStatus(): array
    {
        $security = new Security();

        return [
            'app' => [
                'installed' => $this->isInstalled(),
                'base_url' => $this->get('app.base_url'),
                'timezone' => $this->get('app.timezone'),
                'version' => $this->get('app.version', '0.1.0'),
                'build' => $this->buildFingerprint(),
            ],
            'telegram' => [
                'configured' => (string) $this->get('telegram.bot_token', '') !== '',
                'bot_token' => $security->maskSecret((string) $this->get('telegram.bot_token', '')),
                'owner_chat_id' => $this->get('telegram.owner_chat_id'),
                'owner_username' => $this->get('telegram.owner_username'),
            ],
            'ghost' => [
                'configured' => (string) $this->get('ghost.api_token', '') !== '',
                'api_base' => $this->get('ghost.api_base'),
                'api_token' => $security->maskSecret((string) $this->get('ghost.api_token', '')),
                'default_group_id' => $this->get('ghost.default_group_id'),
            ],
        ];
    }

    public function buildFingerprint(): string
    {
        if ($this->buildFingerprint !== null) {
            return $this->buildFingerprint;
        }

        $moduleRoot = dirname($this->configPath);
        $sources = [
            $moduleRoot . '/src/InboxPoller.php',
            $moduleRoot . '/src/ChatHandler.php',
            $moduleRoot . '/src/GhostClient.php',
            $moduleRoot . '/src/CommandRouter.php',
            $moduleRoot . '/src/bootstrap.php',
            $moduleRoot . '/public/cron.php',
            $moduleRoot . '/public/health.php',
            $moduleRoot . '/public/maintenance.php',
            $moduleRoot . '/public/webhook.php',
        ];

        $parts = [(string) $this->get('app.version', '0.1.0')];
        foreach ($sources as $path) {
            if (is_file($path)) {
                $parts[] = basename($path) . ':' . sha1_file($path);
            }
        }

        $this->buildFingerprint = substr(sha1(implode('|', $parts)), 0, 12);
        return $this->buildFingerprint;
    }

    private function load(): array
    {
        $defaults = $this->defaults();
        if (!is_file($this->configPath)) {
            return $defaults;
        }

        $loaded = require $this->configPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array.');
        }

        return $this->mergeRecursive($defaults, $loaded);
    }

    private function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private function defaults(): array
    {
        return [
            'app' => [
                'installed' => false,
                'base_url' => '',
                'timezone' => 'UTC',
                'log_level' => 'info',
                'cron_secret' => '',
                'status_secret' => '',
                'health_secret' => '',
                'installer_secret_hash' => '',
                'version' => '0.1.0',
            ],
            'telegram' => [
                'bot_token' => '',
                'webhook_secret' => '',
                'owner_chat_id' => null,
                'owner_username' => null,
            ],
            'ghost' => [
                'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2',
                'api_token' => '',
                'default_group_id' => '',
                'ghost_id_mode' => 'token',
                'paths' => [
                    'chat' => '/chat',
                    'inbox' => '/inbox',
                    'inbox_groups' => '/inbox_groups',
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
                'cron_batch_size' => 50,
            ],
        ];
    }
}
