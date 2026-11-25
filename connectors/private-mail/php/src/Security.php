<?php

declare(strict_types=1);

namespace MantisBat\PrivateMail;

final class Security
{
    public function token(int $bytes = 24): string { return bin2hex(random_bytes($bytes)); }
    public function hash(string $secret): string { return password_hash($secret, PASSWORD_DEFAULT); }
    public function verify(?string $secret, ?string $hash): bool
    {
        return is_string($secret) && $secret !== '' && is_string($hash) && $hash !== '' && password_verify($secret, $hash);
    }
    public function equals(?string $expected, ?string $actual): bool
    {
        return is_string($expected) && $expected !== '' && is_string($actual) && $actual !== '' && hash_equals($expected, $actual);
    }
}
