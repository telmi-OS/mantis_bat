<?php

declare(strict_types=1);

namespace MantisBat\NextcloudTalkConnect;

use RuntimeException;

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
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }

    public function all(): array { return $this->data; }
    public function exists(): bool { return is_file($this->path); }
    public function isInstalled(): bool { return (bool) $this->get('app.installed', false); }

    public function status(Security $security): array
    {
        return [
            'app' => [
                'installed' => $this->isInstalled(), 'base_url' => $this->get('app.base_url'),
                'version' => $this->get('app.version'), 'build' => $this->buildFingerprint(),
            ],
            'api' => ['auth_key' => $security->mask((string) $this->get('api.auth_key', ''))],
            'nextcloud' => [
                'base_url' => $this->get('nextcloud.base_url'),
                'username' => $this->get('nextcloud.username'),
                'app_password' => $security->mask((string) $this->get('nextcloud.app_password', '')),
            ],
            'meeting' => ['default_name' => $this->get('meeting.default_name'), 'name_max_length' => $this->get('meeting.name_max_length')],
        ];
    }

    public function buildFingerprint(): string
    {
        $root = dirname($this->path, 2);
        $files = ['/src/bootstrap.php', '/src/Config.php', '/src/Storage.php', '/src/TalkClient.php', '/public/index.php', '/public/install.php'];
        $parts = [(string) $this->get('app.version', '0.1.0')];
        foreach ($files as $relative) if (is_file($root . $relative)) $parts[] = $relative . ':' . sha1_file($root . $relative);
        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Could not create configuration directory.');
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
            'app' => ['installed' => false, 'base_url' => '', 'timezone' => 'UTC', 'version' => '0.1.0', 'status_secret' => '', 'access_secret' => '', 'installer_secret_hash' => ''],
            'api' => ['auth_key' => ''],
            'nextcloud' => ['base_url' => '', 'username' => '', 'app_password' => '', 'timeout_seconds' => 10],
            'meeting' => ['default_name' => 'Teleport AI Meeting', 'name_max_length' => 100, 'dedupe_seconds' => 300],
        ];
        if (!is_file($this->path)) return $defaults;
        $loaded = require $this->path;
        if (!is_array($loaded)) throw new RuntimeException('Configuration must return an array.');
        return $this->merge($defaults, $loaded);
    }

    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) ? $this->merge($base[$key], $value) : $value;
        }
        return $base;
    }
}
