<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class Installer
{
    public function __construct(private readonly string $root, private readonly Crypto $crypto)
    {
    }

    public function configPath(): string { return $this->root . '/storage/config.php'; }
    public function databasePath(): string { return $this->root . '/storage/google_calendar.sqlite'; }
    public function cronKeyPath(): string { return $this->root . '/storage/cron-unlock.key'; }
    public function lockPath(): string { return $this->root . '/storage/installed.lock'; }

    public function requirements(): array
    {
        $storage = $this->root . '/storage';
        if (!is_dir($storage)) {
            @mkdir($storage, 0700, true);
        }
        return [
            'php_8_1_or_newer' => PHP_VERSION_ID >= 80100,
            'curl' => extension_loaded('curl'),
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'hash' => function_exists('hash_hmac') && function_exists('hash_pbkdf2'),
            'random_bytes' => function_exists('random_bytes'),
            'storage_writable' => is_dir($storage) && is_writable($storage),
        ];
    }

    public function requirementsPass(): bool
    {
        return !in_array(false, $this->requirements(), true);
    }

    public function createConfig(array $input): array
    {
        foreach (['password', 'google_client_id', 'google_client_secret', 'ghost_api_token', 'group_name'] as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                throw new RuntimeException('Missing required installation value: ' . $required);
            }
        }

        $appPassword = (string) $input['password'];
        if (strlen($appPassword) < 12) {
            throw new RuntimeException('Application password must contain at least 12 characters.');
        }
        try {
            new \DateTimeZone((string) ($input['timezone'] ?? 'UTC'));
        } catch (\Throwable) {
            throw new RuntimeException('Timezone is invalid.');
        }

        $masterKey = random_bytes(32);
        $passwordSalt = $this->crypto->randomSecret(16);
        $cronSecret = $this->crypto->randomSecret(32);
        $cronSalt = $this->crypto->randomSecret(16);
        $passwordKey = $this->crypto->deriveKey($appPassword, $passwordSalt);
        $cronKey = $this->crypto->deriveKey($cronSecret, $cronSalt);

        $cronPath = $this->cronKeyPath();
        if (file_put_contents($cronPath, $cronSecret . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not create the private cron unlock key.');
        }
        @chmod($cronPath, 0600);

        $config = [
            'app' => [
                'installed' => true,
                'version' => '0.1.0',
                'base_url' => rtrim((string) ($input['base_url'] ?? ''), '/'),
                'timezone' => (string) ($input['timezone'] ?? 'Europe/Berlin'),
                'password_hash' => password_hash($appPassword, PASSWORD_DEFAULT),
                'password_salt' => $passwordSalt,
                'password_wrap' => $this->crypto->seal($masterKey, $passwordKey),
                'cron_salt' => $cronSalt,
                'cron_wrap' => $this->crypto->seal($masterKey, $cronKey),
                'cron_key_path' => $cronPath,
                'cron_secret' => $this->crypto->randomSecret(24),
            ],
            'crypto' => ['scheme' => 'gca1', 'kdf' => 'pbkdf2-sha256'],
            'paths' => ['chat' => '/chat'],
            'limits' => ['window_days' => 28, 'min_interval_minutes' => 15, 'max_prompt_chars' => 120000],
        ];

        return [$config, $masterKey];
    }

    public function lock(): void
    {
        file_put_contents($this->lockPath(), date('c') . "\n", LOCK_EX);
        @chmod($this->lockPath(), 0600);
    }

    public function isLocked(): bool { return is_file($this->lockPath()); }
}
