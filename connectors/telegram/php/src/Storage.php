<?php

declare(strict_types=1);

namespace MantisBat;

use PDO;
use RuntimeException;

final class Storage
{
    private PDO $pdo;

    public function __construct(private readonly string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create storage directory.');
        }

        $this->pdo = new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    public function migrate(): void
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS pairing_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT \'pending\', telegram_user_id TEXT, telegram_chat_id TEXT, telegram_username TEXT, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, used_at INTEGER)',
            'CREATE TABLE IF NOT EXISTS telegram_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_user_id TEXT NOT NULL UNIQUE, telegram_chat_id TEXT NOT NULL, username TEXT, first_name TEXT, last_name TEXT, status TEXT NOT NULL DEFAULT \'active\', created_at INTEGER NOT NULL, verified_at INTEGER NOT NULL, revoked_at INTEGER)',
            'CREATE TABLE IF NOT EXISTS delivered_inbox_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, ghost_message_id TEXT NOT NULL, telegram_chat_id TEXT NOT NULL, delivered_at INTEGER NOT NULL, acked_at INTEGER, UNIQUE(ghost_message_id, telegram_chat_id))',
            'CREATE TABLE IF NOT EXISTS inbound_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_update_id TEXT, telegram_message_id TEXT, telegram_user_id TEXT, telegram_chat_id TEXT, message_type TEXT NOT NULL, text TEXT, command TEXT, status TEXT NOT NULL, created_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT NOT NULL, message TEXT NOT NULL, context_json TEXT, created_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS request_limits (bucket TEXT NOT NULL, subject TEXT NOT NULL, count INTEGER NOT NULL, reset_at INTEGER NOT NULL, PRIMARY KEY(bucket, subject))',
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }
    }

    public function insertLog(string $level, string $message, string $contextJson, int $createdAt): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO logs (level, message, context_json, created_at) VALUES (:level, :message, :context_json, :created_at)');
        $stmt->execute([
            ':level' => $level,
            ':message' => $message,
            ':context_json' => $contextJson,
            ':created_at' => $createdAt,
        ]);
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':updated_at' => time(),
        ]);
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function createPairingCode(string $code, int $expiresAt): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO pairing_codes (code, status, created_at, expires_at) VALUES (:code, :status, :created_at, :expires_at)');
        $stmt->execute([
            ':code' => $code,
            ':status' => 'pending',
            ':created_at' => time(),
            ':expires_at' => $expiresAt,
        ]);
    }

    public function consumePairingCode(string $code, array $telegramUser): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pairing_codes WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['status'] !== 'pending' || (int) $row['expires_at'] < time()) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare('UPDATE pairing_codes SET status = :status, telegram_user_id = :telegram_user_id, telegram_chat_id = :telegram_chat_id, telegram_username = :telegram_username, used_at = :used_at WHERE code = :code');
            $update->execute([
                ':status' => 'used',
                ':telegram_user_id' => (string) ($telegramUser['user_id'] ?? ''),
                ':telegram_chat_id' => (string) ($telegramUser['chat_id'] ?? ''),
                ':telegram_username' => (string) ($telegramUser['username'] ?? ''),
                ':used_at' => time(),
                ':code' => $code,
            ]);

            $this->upsertTelegramAccount($telegramUser);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function upsertTelegramAccount(array $telegramUser): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO telegram_accounts (telegram_user_id, telegram_chat_id, username, first_name, last_name, status, created_at, verified_at) VALUES (:telegram_user_id, :telegram_chat_id, :username, :first_name, :last_name, :status, :created_at, :verified_at) ON CONFLICT(telegram_user_id) DO UPDATE SET telegram_chat_id = excluded.telegram_chat_id, username = excluded.username, first_name = excluded.first_name, last_name = excluded.last_name, status = excluded.status, verified_at = excluded.verified_at, revoked_at = NULL');
        $stmt->execute([
            ':telegram_user_id' => (string) ($telegramUser['user_id'] ?? ''),
            ':telegram_chat_id' => (string) ($telegramUser['chat_id'] ?? ''),
            ':username' => (string) ($telegramUser['username'] ?? ''),
            ':first_name' => (string) ($telegramUser['first_name'] ?? ''),
            ':last_name' => (string) ($telegramUser['last_name'] ?? ''),
            ':status' => 'active',
            ':created_at' => time(),
            ':verified_at' => time(),
        ]);
    }

    public function isAuthorizedTelegramUser(string $telegramUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM telegram_accounts WHERE telegram_user_id = :telegram_user_id AND status = :status LIMIT 1');
        $stmt->execute([
            ':telegram_user_id' => $telegramUserId,
            ':status' => 'active',
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function getAuthorizedOwner(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM telegram_accounts WHERE status = \'active\' ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function recordInboundMessage(array $payload): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO inbound_messages (telegram_update_id, telegram_message_id, telegram_user_id, telegram_chat_id, message_type, text, command, status, created_at) VALUES (:telegram_update_id, :telegram_message_id, :telegram_user_id, :telegram_chat_id, :message_type, :text, :command, :status, :created_at)');
        $stmt->execute([
            ':telegram_update_id' => (string) ($payload['telegram_update_id'] ?? ''),
            ':telegram_message_id' => (string) ($payload['telegram_message_id'] ?? ''),
            ':telegram_user_id' => (string) ($payload['telegram_user_id'] ?? ''),
            ':telegram_chat_id' => (string) ($payload['telegram_chat_id'] ?? ''),
            ':message_type' => (string) ($payload['message_type'] ?? 'text'),
            ':text' => (string) ($payload['text'] ?? ''),
            ':command' => (string) ($payload['command'] ?? ''),
            ':status' => (string) ($payload['status'] ?? 'received'),
            ':created_at' => time(),
        ]);
    }

    public function hasDeliveredInboxMessage(string $ghostMessageId, string $telegramChatId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM delivered_inbox_messages WHERE ghost_message_id = :ghost_message_id AND telegram_chat_id = :telegram_chat_id LIMIT 1');
        $stmt->execute([
            ':ghost_message_id' => $ghostMessageId,
            ':telegram_chat_id' => $telegramChatId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function markInboxDelivered(string $ghostMessageId, string $telegramChatId): void
    {
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO delivered_inbox_messages (ghost_message_id, telegram_chat_id, delivered_at) VALUES (:ghost_message_id, :telegram_chat_id, :delivered_at)');
        $stmt->execute([
            ':ghost_message_id' => $ghostMessageId,
            ':telegram_chat_id' => $telegramChatId,
            ':delivered_at' => time(),
        ]);
    }

    public function markInboxAcked(string $ghostMessageId, string $telegramChatId): void
    {
        $stmt = $this->pdo->prepare('UPDATE delivered_inbox_messages SET acked_at = :acked_at WHERE ghost_message_id = :ghost_message_id AND telegram_chat_id = :telegram_chat_id');
        $stmt->execute([
            ':acked_at' => time(),
            ':ghost_message_id' => $ghostMessageId,
            ':telegram_chat_id' => $telegramChatId,
        ]);
    }

    public function hitRateLimit(string $bucket, string $subject, int $limit, int $windowSeconds): bool
    {
        $subject = $subject !== '' ? $subject : 'unknown';
        $now = time();

        $stmt = $this->pdo->prepare('SELECT count, reset_at FROM request_limits WHERE bucket = :bucket AND subject = :subject LIMIT 1');
        $stmt->execute([
            ':bucket' => $bucket,
            ':subject' => $subject,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int) $row['reset_at'] <= $now) {
            $upsert = $this->pdo->prepare('INSERT INTO request_limits (bucket, subject, count, reset_at) VALUES (:bucket, :subject, :count, :reset_at) ON CONFLICT(bucket, subject) DO UPDATE SET count = excluded.count, reset_at = excluded.reset_at');
            $upsert->execute([
                ':bucket' => $bucket,
                ':subject' => $subject,
                ':count' => 1,
                ':reset_at' => $now + $windowSeconds,
            ]);

            return false;
        }

        $count = (int) $row['count'] + 1;
        $update = $this->pdo->prepare('UPDATE request_limits SET count = :count WHERE bucket = :bucket AND subject = :subject');
        $update->execute([
            ':count' => $count,
            ':bucket' => $bucket,
            ':subject' => $subject,
        ]);

        return $count > $limit;
    }
}
