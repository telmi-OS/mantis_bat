<?php

declare(strict_types=1);

namespace MantisBat\GhostShell;

use DateTimeZone;
use RuntimeException;

/** Optional display settings for the static Ghost Shell guide. */
final class Config
{
    private array $data;

    public function __construct(private readonly string $path)
    {
        $this->data = $this->load();
        $timezone = (string) $this->get('app.timezone', 'UTC');
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);
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

    public function publicAsset(string $key, string $publicRoot): ?string
    {
        $relative = $this->get($key);
        if (!is_string($relative) || !preg_match('#^assets/[A-Za-z0-9._/-]+$#', $relative)) {
            return null;
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        $root = realpath($publicRoot);
        $asset = realpath($publicRoot . DIRECTORY_SEPARATOR . $relative);
        if ($root === false || $asset === false || !is_file($asset)
            || !str_starts_with($asset, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $relative;
    }

    public function buildFingerprint(): string
    {
        $root = dirname($this->path, 2);
        $files = [
            '/src/bootstrap.php', '/src/Config.php', '/public/index.php',
            '/public/assets/theme.css', '/public/assets/guide.js',
            '/public/downloads/install-ghost-shell.sh',
            '/public/assets/images/ghost-shell-start.webp',
            '/public/assets/images/ghost-shell-end.webp',
            '/storage/config.example.php',
        ];
        $parts = [(string) $this->get('app.version', '1.0.0')];
        foreach ($files as $relative) {
            if (is_file($root . $relative)) {
                $parts[] = $relative . ':' . sha1_file($root . $relative);
            }
        }
        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    private function load(): array
    {
        $defaults = [
            'app' => ['title' => 'Ghost Shell', 'version' => '1.0.0', 'timezone' => 'UTC'],
            'guide' => [
                'start_image' => 'assets/images/ghost-shell-start.webp',
                'end_image' => 'assets/images/ghost-shell-end.webp',
            ],
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
