<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

use RuntimeException;

/** Runtime configuration lives in the private Hades instance directory. */
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
    public function installed(): bool { return (bool) $this->get('app.installed', false); }
    public function path(): string { return $this->path; }

    public function write(array $data): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create private storage directory.');
        }
        $temporary = $this->path . '.tmp';
        $contents = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not save private configuration.');
        }
        @chmod($this->path, 0600);
        $this->data = $data;
        date_default_timezone_set((string) $this->get('app.timezone', 'UTC'));
    }

    public function status(): array
    {
        $mask = static function (string $value): string {
            if ($value === '') return '';
            return strlen($value) < 9 ? str_repeat('*', strlen($value)) : substr($value, 0, 4) . '…' . substr($value, -3);
        };
        return [
            'installed' => $this->installed(),
            'app_url' => $this->get('app.base_url', ''),
            'timezone' => $this->get('app.timezone', 'UTC'),
            'owner' => $this->get('owner.name', ''),
            'owner_email' => $this->get('owner.email', ''),
            'imap_host' => $this->get('imap.host', ''),
            'imap_username' => $mask((string) $this->get('imap.username', '')),
            'smtp_host' => $this->get('smtp.host', ''),
            'ghost_api' => $this->get('ghost.api_base', ''),
            'ghost_jwt' => $mask((string) $this->get('ghost.api_token', '')),
            'notify_destination' => $this->get('notifications.destination', []),
            'folders' => $this->get('mail.folders', []),
            'processing' => $this->get('processing', []),
        ];
    }

    private function load(): array
    {
        if (!is_file($this->path)) return [];
        $loaded = require $this->path;
        if (!is_array($loaded)) throw new RuntimeException('Configuration file must return an array.');
        return $loaded;
    }
}
