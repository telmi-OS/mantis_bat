<?php

declare(strict_types=1);

namespace MantisBat\GhostEval;

use RuntimeException;

final class Installer
{
    public function __construct(private readonly string $root)
    {
    }

    public function storagePath(): string { return $this->root . '/storage'; }
    public function databasePath(): string { return $this->storagePath() . '/ghost_eval.sqlite'; }
    public function configPath(): string { return $this->storagePath() . '/config.php'; }
    public function lockPath(): string { return $this->storagePath() . '/installed.lock'; }
    public function logPath(): string { return $this->storagePath() . '/ghost_eval.log'; }
    public function reportsPath(): string { return $this->storagePath() . '/reports'; }

    public function requirements(): array
    {
        foreach ([$this->storagePath(), $this->reportsPath()] as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        return [
            'php_8_1_or_newer' => PHP_VERSION_ID >= 80100,
            'curl' => extension_loaded('curl'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'storage_writable' => is_writable($this->storagePath()),
            'reports_writable' => is_writable($this->reportsPath()),
        ];
    }

    public function requirementsPass(): bool
    {
        foreach ($this->requirements() as $ok) {
            if ($ok !== true) return false;
        }
        return true;
    }

    public function lock(): void
    {
        if (file_put_contents($this->lockPath(), date(DATE_ATOM), LOCK_EX) === false) {
            throw new RuntimeException('Could not lock the installer.');
        }
    }

    public function reset(): void
    {
        foreach ([$this->databasePath(), $this->configPath(), $this->lockPath(), $this->logPath()] as $path) {
            if (is_file($path)) @unlink($path);
        }
        foreach (glob($this->reportsPath() . '/*') ?: [] as $path) {
            if (is_file($path)) @unlink($path);
        }
    }
}
