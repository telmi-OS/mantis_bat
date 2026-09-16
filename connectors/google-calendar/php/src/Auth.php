<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class Auth
{
    private const SESSION_KEY = 'mantis_bat_google_calendar_master_key';

    public function __construct(private readonly Config $config, private readonly Crypto $crypto)
    {
    }

    public function login(string $password): bool
    {
        $hash = (string) $this->config->get('app.password_hash', '');
        if ($hash === '' || !password_verify($password, $hash)) return false;
        $key = $this->openPasswordKey($password);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = base64_encode($key);
        $_SESSION['mantis_bat_google_calendar_authenticated_at'] = time();
        return true;
    }

    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        unset($_SESSION[self::SESSION_KEY], $_SESSION['mantis_bat_google_calendar_authenticated_at']);
    }

    public function sessionKey(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $encoded = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($encoded) || $encoded === '') return null;
        $key = base64_decode($encoded, true);
        return $key === false || strlen($key) !== 32 ? null : $key;
    }

    public function requireSessionKey(): string
    {
        $key = $this->sessionKey();
        if ($key === null) throw new RuntimeException('Authentication required.');
        return $key;
    }

    public function cronKey(): string
    {
        $path = (string) $this->config->get('app.cron_key_path', '');
        $secret = trim((string) (@file_get_contents($path) ?: ''));
        if ($path === '' || $secret === '') throw new RuntimeException('Private cron unlock key is unavailable.');
        $derived = $this->crypto->deriveKey($secret, (string) $this->config->get('app.cron_salt', ''));
        return $this->crypto->open((string) $this->config->get('app.cron_wrap', ''), $derived);
    }

    public function changePassword(string $newPassword): void
    {
        if (strlen($newPassword) < 12) throw new RuntimeException('Application password must contain at least 12 characters.');
        $key = $this->requireSessionKey();
        $salt = $this->crypto->randomSecret(16);
        $data = $this->config->all();
        $data['app']['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $data['app']['password_salt'] = $salt;
        $data['app']['password_wrap'] = $this->crypto->seal($key, $this->crypto->deriveKey($newPassword, $salt));
        $this->config->write($data);
    }

    private function openPasswordKey(string $password): string
    {
        $derived = $this->crypto->deriveKey($password, (string) $this->config->get('app.password_salt', ''));
        $key = $this->crypto->open((string) $this->config->get('app.password_wrap', ''), $derived);
        if (strlen($key) !== 32) throw new RuntimeException('Stored encryption key is invalid.');
        return $key;
    }
}
