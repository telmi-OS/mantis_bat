<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class Installer
{
    public function __construct(
        private readonly string $moduleRoot,
        private readonly Security $security
    ) {
    }

    public function requirements(): array
    {
        $storageDir = $this->moduleRoot . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        return [
            'php_version' => PHP_VERSION_ID >= 80100,
            'curl' => extension_loaded('curl'),
            'sqlite' => extension_loaded('pdo_sqlite'),
            'storage_writable' => is_dir($storageDir) && is_writable($storageDir),
        ];
    }

    public function allRequirementsPass(): bool
    {
        foreach ($this->requirements() as $result) {
            if ($result !== true) {
                return false;
            }
        }

        return true;
    }

    public function configPath(): string
    {
        return $this->moduleRoot . '/storage/config.php';
    }

    public function databasePath(): string
    {
        return $this->moduleRoot . '/storage/mantis_bat.sqlite';
    }

    public function lockPath(): string
    {
        return $this->moduleRoot . '/storage/installed.lock';
    }

    public function writeConfig(array $config): void
    {
        $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($this->configPath(), $export) === false) {
            throw new RuntimeException('Could not write config file.');
        }
    }

    public function lock(string $token): void
    {
        file_put_contents($this->lockPath(), json_encode(['locked_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function resetRuntime(): void
    {
        foreach ([
            $this->databasePath(),
            $this->configPath(),
            $this->lockPath(),
            $this->moduleRoot . '/storage/mantis_bat.log',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
