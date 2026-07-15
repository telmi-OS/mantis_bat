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
            'zip' => class_exists(\ZipArchive::class),
            'dom' => class_exists(\DOMDocument::class),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'pdftotext' => $this->hasBinary('pdftotext'),
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

    public function logPath(): string
    {
        return $this->moduleRoot . '/storage/mantis_bat.log';
    }

    public function jobsPath(): string
    {
        return $this->moduleRoot . '/storage/jobs';
    }

    public function uploadsPath(): string
    {
        return $this->moduleRoot . '/storage/uploads';
    }

    public function ensureRuntimeDirectories(): void
    {
        foreach ([
            $this->moduleRoot . '/storage',
            $this->jobsPath(),
            $this->uploadsPath(),
        ] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create runtime directory: ' . $directory);
            }
        }
    }

    public function writeConfig(array $config): void
    {
        $this->ensureRuntimeDirectories();
        $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($this->configPath(), $export) === false) {
            throw new RuntimeException('Could not write config file.');
        }
    }

    public function lock(): void
    {
        if (file_put_contents($this->lockPath(), json_encode(['locked_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Could not write install lock.');
        }
    }

    public function resetRuntime(): void
    {
        foreach ([
            $this->databasePath(),
            $this->configPath(),
            $this->lockPath(),
            $this->logPath(),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->deleteDirectory($this->jobsPath());
        $this->deleteDirectory($this->uploadsPath());
    }

    private function hasBinary(string $binary): bool
    {
        $command = sprintf('command -v %s 2>/dev/null', escapeshellarg($binary));
        $output = shell_exec($command);
        return is_string($output) && trim($output) !== '';
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }
            @unlink($child);
        }

        @rmdir($path);
    }
}
