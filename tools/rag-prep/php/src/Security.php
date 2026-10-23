<?php

declare(strict_types=1);

namespace MantisBat;

final class Security
{
    public function randomToken(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_DEFAULT);
    }

    public function verifySecret(?string $secret, ?string $hash): bool
    {
        if ($secret === null || $hash === null || $hash === '') {
            return false;
        }

        return password_verify($secret, $hash);
    }

    public function constantTimeEquals(?string $expected, ?string $actual): bool
    {
        $expected = (string) $expected;
        $actual = (string) $actual;

        if ($expected === '' || $actual === '') {
            return false;
        }

        return hash_equals($expected, $actual);
    }

    public function maskSecret(?string $value): string
    {
        $value = (string) $value;
        $length = strlen($value);

        if ($length <= 6) {
            return str_repeat('*', max($length, 3));
        }

        return substr($value, 0, 4) . '...' . substr($value, -2);
    }

    public function redact(string $text, array $secrets = []): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i',
            '/\b\d{5,}:[A-Za-z0-9_-]{10,}\b/',
            '/(GHOST_API_TOKEN|TELEGRAM_BOT_TOKEN)\s*=\s*[^\s]+/i',
        ];

        $redacted = preg_replace($patterns, '[REDACTED]', $text) ?? $text;

        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if ($secret !== '') {
                $redacted = str_replace($secret, '[REDACTED]', $redacted);
            }
        }

        return $redacted;
    }
}
