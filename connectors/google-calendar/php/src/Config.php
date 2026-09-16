<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class Config
{
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = $this->load();
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

    public function all(): array { return $this->data; }
    public function path(): string { return $this->path; }
    public function installed(): bool { return (bool) $this->get('app.installed', false); }

    public function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create configuration directory.');
        }
        $contents = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write configuration.');
        }
        @chmod($this->path, 0600);
        $this->data = $data;
    }

    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $loaded = require $this->path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Configuration must return an array.');
        }
        return $loaded;
    }
}
