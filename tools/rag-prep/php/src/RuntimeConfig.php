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
            'ghost' => [
                'configured' => (string) $this->get('ghost.api_token', '') !== '',
                'api_base' => $this->get('ghost.api_base'),
                'api_token' => $security->maskSecret((string) $this->get('ghost.api_token', '')),
            ],
            'limits' => [
                'max_file_size_mb' => $this->get('limits.max_file_size_mb', 15),
                'max_job_size_mb' => $this->get('limits.max_job_size_mb', 20),
                'max_files_per_job' => $this->get('limits.max_files_per_job', 5),
                'max_source_characters' => $this->get('limits.max_source_characters', 120000),
            ],
            'features' => [
                'pdf_support' => (bool) $this->get('features.pdf_support', true),
                'docx_support' => (bool) $this->get('features.docx_support', true),
                'txt_support' => (bool) $this->get('features.txt_support', true),
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
            $moduleRoot . '/src/bootstrap.php',
            $moduleRoot . '/src/RuntimeConfig.php',
            $moduleRoot . '/src/Storage.php',
            $moduleRoot . '/src/GhostClient.php',
            $moduleRoot . '/src/DocumentExtractor.php',
            $moduleRoot . '/src/JobProcessor.php',
            $moduleRoot . '/public/index.php',
            $moduleRoot . '/public/install.php',
            $moduleRoot . '/public/cron.php',
            $moduleRoot . '/public/maintenance.php',
            $moduleRoot . '/public/download.php',
            $moduleRoot . '/public/health.php',
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
                'access_secret' => '',
                'installer_secret_hash' => '',
                'version' => '0.1.0',
            ],
            'ghost' => [
                'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2',
                'api_token' => '',
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
    }
}
