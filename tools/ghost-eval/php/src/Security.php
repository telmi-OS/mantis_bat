<?php

declare(strict_types=1);

namespace MantisBat\GhostEval;

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
        return is_string($secret) && $secret !== '' && is_string($hash) && $hash !== '' && password_verify($secret, $hash);
    }

    public function equals(?string $expected, ?string $actual): bool
    {
        return is_string($expected) && $expected !== '' && is_string($actual) && $actual !== '' && hash_equals($expected, $actual);
    }

    public function mask(string $secret): string
    {
        if (strlen($secret) < 8) {
            return str_repeat('*', max(3, strlen($secret)));
        }
        return substr($secret, 0, 4) . '…' . substr($secret, -3);
    }
}
