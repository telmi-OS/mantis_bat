<?php

declare(strict_types=1);

namespace MantisBat\NextcloudTalkConnect;

use RuntimeException;

final class Installer
{
    public function __construct(private readonly string $root) {}
    public function storagePath(): string { return $this->root . '/storage'; }
    public function databasePath(): string { return $this->storagePath() . '/nextcloud_talk.sqlite'; }
    public function configPath(): string { return $this->storagePath() . '/config.php'; }
    public function lockPath(): string { return $this->storagePath() . '/installed.lock'; }
    public function logPath(): string { return $this->storagePath() . '/nextcloud_talk.log'; }
    public function dedupeLockPath(): string { return $this->storagePath() . '/dedupe-locks'; }

    public function requirements(): array
    {
        foreach ([$this->storagePath(), $this->dedupeLockPath()] as $path) if (!is_dir($path)) @mkdir($path, 0775, true);
        return [
            'php_8_1_or_newer' => PHP_VERSION_ID >= 80100,
            'curl' => extension_loaded('curl'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'mbstring' => extension_loaded('mbstring'),
            'storage_writable' => is_writable($this->storagePath()),
            'dedupe_locks_writable' => is_writable($this->dedupeLockPath()),
        ];
    }

    public function requirementsPass(): bool
    {
        foreach ($this->requirements() as $ok) if ($ok !== true) return false;
        return true;
    }

    public function lock(): void
    {
        if (file_put_contents($this->lockPath(), date(DATE_ATOM), LOCK_EX) === false) throw new RuntimeException('Could not lock the installer.');
        @chmod($this->lockPath(), 0600);
    }

    public function reset(): void
    {
        foreach ([$this->databasePath(), $this->databasePath() . '-wal', $this->databasePath() . '-shm', $this->configPath(), $this->lockPath(), $this->logPath()] as $path) if (is_file($path)) @unlink($path);
        foreach (glob($this->dedupeLockPath() . '/*') ?: [] as $path) if (is_file($path)) @unlink($path);
    }
}
