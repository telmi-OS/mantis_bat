<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

/**
 * Small, dependency-free authenticated encryption helper.
 *
 * It deliberately uses only PHP's core hash, random_bytes and base64
 * functions. Values are encrypted with an HMAC-derived stream and verified
 * before they are returned. This protects a copied SQLite database without
 * requiring Sodium, OpenSSL or SQLCipher on the host.
 */
final class Crypto
{
    private const VERSION = 'gca1';
    private const PBKDF2_ROUNDS = 210000;

    public function deriveKey(string $password, string $salt): string
    {
        if ($password === '' || $salt === '') {
            throw new RuntimeException('Encryption password and salt are required.');
        }

        return hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ROUNDS, 32, true);
    }

    public function seal(string $plaintext, string $key): string
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('Encryption key is too short.');
        }

        $nonce = random_bytes(16);
        $ciphertext = '';
        $length = strlen($plaintext);
        for ($offset = 0, $counter = 0; $offset < $length; $offset += 32, $counter++) {
            $stream = hash_hmac('sha256', $nonce . pack('N2', 0, $counter), $key, true);
            $chunk = substr($plaintext, $offset, 32);
            $ciphertext .= $chunk ^ substr($stream, 0, strlen($chunk));
        }

        $mac = hash_hmac('sha256', self::VERSION . $nonce . $ciphertext, $key, true);
        return self::VERSION . '$' . base64_encode($nonce . $mac . $ciphertext);
    }

    public function open(string $sealed, string $key): string
    {
        $parts = explode('$', $sealed, 2);
        if (count($parts) !== 2 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Encrypted value has an invalid format.');
        }

        $payload = base64_decode($parts[1], true);
        if ($payload === false || strlen($payload) < 48) {
            throw new RuntimeException('Encrypted value is invalid.');
        }

        $nonce = substr($payload, 0, 16);
        $mac = substr($payload, 16, 32);
        $ciphertext = substr($payload, 48);
        $expected = hash_hmac('sha256', self::VERSION . $nonce . $ciphertext, $key, true);
        if (!hash_equals($expected, $mac)) {
            throw new RuntimeException('Encrypted value failed authentication.');
        }

        $plaintext = '';
        $length = strlen($ciphertext);
        for ($offset = 0, $counter = 0; $offset < $length; $offset += 32, $counter++) {
            $stream = hash_hmac('sha256', $nonce . pack('N2', 0, $counter), $key, true);
            $chunk = substr($ciphertext, $offset, 32);
            $plaintext .= $chunk ^ substr($stream, 0, strlen($chunk));
        }

        return $plaintext;
    }

    public function randomSecret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
