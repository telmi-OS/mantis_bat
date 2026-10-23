<?php

declare(strict_types=1);

namespace MantisBat\NextcloudTalkConnect;

use PDO;
use RuntimeException;

final class Storage
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create private storage directory.');
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA busy_timeout = 15000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        @chmod($path, 0600);
    }

    public function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
            name_key TEXT PRIMARY KEY,
            meeting_name TEXT NOT NULL,
            token TEXT NOT NULL DEFAULT '',
            meeting_url TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_code TEXT NOT NULL DEFAULT ''
        )");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_meetings_expiry ON meetings(expires_at)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS create_limits (created_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS meeting_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_name TEXT NOT NULL,
            meeting_url TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_meeting_history_created ON meeting_history(created_at DESC)');
    }

    public function recentMeeting(string $nameKey, int $now): ?array
    {
        $stmt = $this->pdo->prepare('SELECT meeting_name, token, meeting_url, created_at, expires_at, status, error_code FROM meetings WHERE name_key=:name AND expires_at>:now');
        $stmt->execute([':name' => $nameKey, ':now' => $now]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function purgeExpiredMeetings(int $now): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM meetings WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
    }

    public function purgeMeetingHistory(int $cutoff): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM meeting_history WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
    }

    public function saveMeeting(string $nameKey, string $name, string $token, string $url, int $reservationAt, int $meetingCreatedAt): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->pdo->prepare("UPDATE meetings SET token=:token,meeting_url=:url,status='success',error_code='' WHERE name_key=:key AND created_at=:created");
            $stmt->execute([':key' => $nameKey, ':token' => $token, ':url' => $url, ':created' => $reservationAt]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('The meeting reservation could not be completed.');
            $history = $this->pdo->prepare('INSERT INTO meeting_history(meeting_name,meeting_url,created_at) VALUES(:name,:url,:created)');
            $history->execute([':name' => $name, ':url' => $url, ':created' => $meetingCreatedAt]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function recentMeetingHistory(int $cutoff, int $limit = 500): array
    {
        $stmt = $this->pdo->prepare('SELECT meeting_name, meeting_url, created_at FROM meeting_history WHERE created_at >= :cutoff ORDER BY created_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
        $stmt->bindValue(':limit', min(500, max(1, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function meetingHistoryCount(int $cutoff): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM meeting_history WHERE created_at >= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    public function reserveMeeting(string $nameKey, string $name, int $createdAt, int $expiresAt): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO meetings(name_key,meeting_name,created_at,expires_at,status)
            VALUES(:key,:name,:created,:expires,'pending')
            ON CONFLICT(name_key) DO UPDATE SET meeting_name=excluded.meeting_name,token='',meeting_url='',created_at=excluded.created_at,expires_at=excluded.expires_at,status='pending',error_code=''");
        $stmt->execute([':key' => $nameKey, ':name' => $name, ':created' => $createdAt, ':expires' => $expiresAt]);
    }

    public function failMeeting(string $nameKey, int $createdAt, string $errorCode): void
    {
        if (!in_array($errorCode, ['nextcloud_auth_failed', 'meeting_creation_failed'], true)) $errorCode = 'meeting_creation_failed';
        $stmt = $this->pdo->prepare("UPDATE meetings SET status='failed',error_code=:error WHERE name_key=:key AND created_at=:created");
        $stmt->execute([':error' => $errorCode, ':key' => $nameKey, ':created' => $createdAt]);
    }

    public function allowNewCreation(int $now, int $limit = 30, int $window = 60): bool
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $prune = $this->pdo->prepare('DELETE FROM create_limits WHERE created_at < :cutoff');
            $prune->execute([':cutoff' => $now - $window]);
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM create_limits')->fetchColumn();
            if ($count >= $limit) {
                $this->pdo->exec('COMMIT');
                return false;
            }
            $stmt = $this->pdo->prepare('INSERT INTO create_limits(created_at) VALUES(:now)');
            $stmt->execute([':now' => $now]);
            $this->pdo->exec('COMMIT');
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function counts(): array
    {
        return ['active_deduplication_entries' => (int) $this->pdo->query('SELECT COUNT(*) FROM meetings WHERE expires_at > ' . time())->fetchColumn()];
    }

    public function reset(): void
    {
        $this->pdo->exec('DELETE FROM meetings');
        $this->pdo->exec('DELETE FROM create_limits');
        $this->pdo->exec('DELETE FROM meeting_history');
    }
}
