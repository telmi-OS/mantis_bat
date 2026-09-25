<?php

declare(strict_types=1);

namespace MantisBat\GhostEval;

use RuntimeException;

/**
 * Module configuration adapter, following the Config.php convention used by
 * the Mantis Bat Google Calendar connector. The live config remains in storage.
 */
final class Config
{
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = $this->load();
        date_default_timezone_set((string) $this->get('app.timezone', 'UTC'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isInstalled(): bool
    {
        return (bool) $this->get('app.installed', false);
    }

    public function status(Security $security): array
    {
        return [
            'app' => [
                'installed' => $this->isInstalled(),
                'base_url' => $this->get('app.base_url'),
                'timezone' => $this->get('app.timezone'),
                'version' => $this->get('app.version'),
                'build' => $this->buildFingerprint(),
            ],
            'ghost' => [
                'api_base' => $this->get('ghost.api_base'),
                'api_token' => $security->mask((string) $this->get('ghost.api_token', '')),
                'group_id' => $this->get('ghost.group_id'),
                'use_rag' => $this->get('evaluation.use_rag'),
                'use_history' => $this->get('evaluation.use_history'),
            ],
            'files' => [
                'space_id' => $this->get('files.space_id'),
                'group_label' => $this->get('files.group_label'),
                'folder_id' => $this->get('files.folder_id'),
            ],
        ];
    }

    public function buildFingerprint(): string
    {
        $root = dirname($this->path);
        $files = [
            '/src/bootstrap.php', '/src/Config.php', '/src/Storage.php',
            '/src/GhostClient.php', '/src/EvaluationRunner.php',
            '/public/index.php', '/public/install.php', '/public/cron.php',
        ];
        $parts = [(string) $this->get('app.version', '0.1.0')];
        foreach ($files as $relative) {
            if (is_file($root . $relative)) {
                $parts[] = $relative . ':' . sha1_file($root . $relative);
            }
        }
        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create configuration directory.');
        }
        $temporary = $this->path . '.tmp';
        $contents = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not write configuration.');
        }
        @chmod($this->path, 0600);
        $this->data = $data;
    }

    private function load(): array
    {
        $defaults = [
            'app' => [
                'installed' => false, 'base_url' => '', 'timezone' => 'UTC', 'version' => '0.1.0',
                'cron_secret' => '', 'status_secret' => '', 'health_secret' => '', 'access_secret' => '',
                'installer_secret_hash' => '',
            ],
            'ghost' => [
                'api_base' => 'https://dev.telmi-ai.com/api/ghost/v2', 'api_token' => '', 'group_id' => '',
                'group_name' => '',
            ],
            'files' => ['space_id' => '', 'group_label' => '', 'folder_id' => ''],
            'evaluation' => [
                'use_rag' => true, 'use_history' => false,
                'max_cases' => 40, 'judge_rubric' => 'Judge factual support against the supplied memory extract. Mark unsupported claims as fail, a correct refusal as pass for out-of-scope cases, and insufficient evidence as unclear.',
            ],
            'notifications' => ['enabled' => false, 'on_start' => false, 'on_finish' => true, 'on_errors' => true, 'on_p0_failures' => true],
        ];
        if (!is_file($this->path)) {
            return $defaults;
        }
        $loaded = require $this->path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Configuration must return an array.');
        }
        return $this->merge($defaults, $loaded);
    }

    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? $this->merge($base[$key], $value)
                : $value;
        }
        return $base;
    }
}
