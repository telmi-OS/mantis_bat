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

    public function protectedPathChecks(?string $installUrl = null): array
    {
        if ($installUrl === null || $installUrl === '') {
            return [];
        }

        $installParts = parse_url($installUrl);
        if (!is_array($installParts) || !isset($installParts['scheme'], $installParts['host'], $installParts['path'])) {
            return [];
        }

        $base = $installParts['scheme'] . '://' . $installParts['host'];
        if (isset($installParts['port'])) {
            $base .= ':' . $installParts['port'];
        }

        $path = (string) $installParts['path'];
        $rootPath = preg_replace('#/public/install\.php$#', '', $path) ?? $path;
        if ($rootPath === $path) {
            $rootPath = preg_replace('#/install\.php$#', '', $path) ?? $path;
        }

        $tests = [
            '/src/Config.php',
            '/templates/install.html.php',
            '/scripts/package-release.sh',
            '/storage/config.php',
        ];

        $results = [];
        foreach ($tests as $suffix) {
            $url = rtrim($base . $rootPath, '/') . $suffix;
            $results[] = $this->probeUrl($url);
        }

        return $results;
    }

    private function probeUrl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MantisBat-Install-Check/0.1.0',
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'url' => $url,
                'status' => 0,
                'safe' => null,
                'message' => 'Automatic check unavailable: ' . $error,
            ];
        }

        $safe = in_array($status, [401, 403, 404], true);
        return [
            'url' => $url,
            'status' => $status,
            'safe' => $safe,
            'message' => $safe
                ? 'Blocked as expected.'
                : 'Unexpectedly reachable. Treat this deployment as unsafe until fixed.',
        ];
    }
}
