<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use PDO;
use RuntimeException;

final class Storage
{
    private PDO $pdo;

    public function __construct(private readonly string $path, private readonly string $key)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create database directory.');
        }
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, encrypted INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS calendar_events (event_hash TEXT PRIMARY KEY, event_data TEXT NOT NULL, previous_event_data TEXT, fingerprint TEXT NOT NULL, pending INTEGER NOT NULL DEFAULT 1, submitted_fingerprint TEXT, active INTEGER NOT NULL DEFAULT 1, is_recurring INTEGER NOT NULL DEFAULT 0, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL)');
        $columns = array_column($this->pdo->query('PRAGMA table_info(calendar_events)')->fetchAll(), 'name');
        if (!in_array('previous_event_data', $columns, true)) $this->pdo->exec('ALTER TABLE calendar_events ADD COLUMN previous_event_data TEXT');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, submission_id TEXT NOT NULL UNIQUE, status TEXT NOT NULL, item_count INTEGER NOT NULL DEFAULT 0, prompt TEXT, included_hashes TEXT, error TEXT, created_at INTEGER NOT NULL, accepted_at INTEGER)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT NOT NULL, subject TEXT NOT NULL, count INTEGER NOT NULL, reset_at INTEGER NOT NULL, PRIMARY KEY(bucket, subject))');
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings(key,value,encrypted,updated_at) VALUES(:key,:value,1,:now) ON CONFLICT(key) DO UPDATE SET value=excluded.value, encrypted=1, updated_at=excluded.updated_at');
        $stmt->execute([':key' => $key, ':value' => $this->encrypt($value), ':now' => time()]);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value,encrypted FROM settings WHERE key=:key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        if (!$row) return $default;
        return (int) $row['encrypted'] === 1 ? $this->decrypt((string) $row['value']) : (string) $row['value'];
    }

    public function upsertEvent(array $event, bool $recurring): void
    {
        $hash = hash('sha256', (string) $event['identity']);
        $existing = $this->eventByHash($hash);
        if ($existing && ($event['status'] ?? '') === 'cancelled') {
            $previousEvent = json_decode($this->decrypt((string) $existing['event_data']), true);
            if (is_array($previousEvent)) {
                foreach (['title', 'start', 'end', 'all_day', 'timezone'] as $field) {
                    if (!array_key_exists($field, $event) || $event[$field] === '' || $event[$field] === null) {
                        if (array_key_exists($field, $previousEvent)) $event[$field] = $previousEvent[$field];
                    }
                }
            }
        }
        $data = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($data === false) throw new RuntimeException('Could not encode calendar event.');
        $fingerprint = hash('sha256', (string) $data);
        $pending = $existing && (string) $existing['fingerprint'] === $fingerprint ? (int) $existing['pending'] : 1;
        $previous = $existing && (string) $existing['fingerprint'] !== $fingerprint ? (string) $existing['event_data'] : ($existing['previous_event_data'] ?? null);
        $now = time();
        $stmt = $this->pdo->prepare('INSERT INTO calendar_events(event_hash,event_data,previous_event_data,fingerprint,pending,submitted_fingerprint,active,is_recurring,first_seen,last_seen) VALUES(:hash,:data,:previous,:fp,:pending,:submitted,1,:recurring,:now,:now) ON CONFLICT(event_hash) DO UPDATE SET event_data=excluded.event_data, previous_event_data=excluded.previous_event_data, fingerprint=excluded.fingerprint, pending=:pending, active=1, is_recurring=excluded.is_recurring, last_seen=:now');
        $stmt->execute([
            ':hash' => $hash, ':data' => $this->encrypt($data), ':previous' => $previous, ':fp' => $fingerprint,
            ':pending' => $pending, ':submitted' => $existing['submitted_fingerprint'] ?? null,
            ':recurring' => $recurring ? 1 : 0, ':now' => $now,
        ]);
    }

    public function markMissingInactive(array $seenHashes): void
    {
        // We never infer cancellation from a failed or windowed fetch. Explicit
        // cancelled events are written by upsertEvent and remain available.
        if ($seenHashes === []) return;
    }

    public function pendingEvents(): array
    {
        $rows = $this->pdo->query('SELECT * FROM calendar_events WHERE pending=1 ORDER BY event_hash')->fetchAll();
        $events = array_map(fn(array $row): array => $this->decodeEvent($row), $rows);
        usort($events, static fn(array $a, array $b): int => strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? '')));
        return $events;
    }

    public function markSubmitted(array $hashes, string $submissionId): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_events SET pending=0, submitted_fingerprint=fingerprint, previous_event_data=NULL WHERE event_hash=:hash');
        foreach ($hashes as $hash) {
            $stmt->execute([':hash' => $hash]);
        }
        $update = $this->pdo->prepare('UPDATE sync_runs SET status=\'accepted\', accepted_at=:now WHERE submission_id=:id');
        $update->execute([':now' => time(), ':id' => $submissionId]);
    }

    public function createRun(string $submissionId, string $status, int $count, string $prompt, array $hashes): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO sync_runs(submission_id,status,item_count,prompt,included_hashes,created_at) VALUES(:id,:status,:count,:prompt,:hashes,:now)');
        $stmt->execute([
            ':id' => $submissionId, ':status' => $status, ':count' => $count,
            ':prompt' => $this->encrypt($prompt), ':hashes' => $this->encrypt(json_encode($hashes)), ':now' => time(),
        ]);
    }

    public function updateRun(string $submissionId, string $status, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE sync_runs SET status=:status,error=:error WHERE submission_id=:id');
        $stmt->execute([':status' => $status, ':error' => $error === null ? null : $this->encrypt($error), ':id' => $submissionId]);
    }

    public function recentRuns(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sync_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['prompt'] = $row['prompt'] ? $this->decrypt((string) $row['prompt']) : '';
            $row['error'] = $row['error'] ? $this->decrypt((string) $row['error']) : '';
        }
        return $rows;
    }

    public function summary(): array
    {
        return [
            'events' => (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn(),
            'pending' => (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events WHERE pending=1')->fetchColumn(),
            'last_run' => $this->pdo->query('SELECT created_at FROM sync_runs ORDER BY id DESC LIMIT 1')->fetchColumn() ?: null,
        ];
    }

    public function hitRateLimit(string $bucket, string $subject, int $max, int $window): bool
    {
        $now = time();
        $stmt = $this->pdo->prepare('SELECT count,reset_at FROM rate_limits WHERE bucket=:bucket AND subject=:subject');
        $stmt->execute([':bucket' => $bucket, ':subject' => $subject]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['reset_at'] <= $now) {
            $upsert = $this->pdo->prepare('INSERT INTO rate_limits(bucket,subject,count,reset_at) VALUES(:bucket,:subject,1,:reset) ON CONFLICT(bucket,subject) DO UPDATE SET count=1,reset_at=:reset');
            $upsert->execute([':bucket' => $bucket, ':subject' => $subject, ':reset' => $now + $window]);
            return false;
        }
        if ((int) $row['count'] >= $max) return true;
        $up = $this->pdo->prepare('UPDATE rate_limits SET count=count+1 WHERE bucket=:bucket AND subject=:subject');
        $up->execute([':bucket' => $bucket, ':subject' => $subject]);
        return false;
    }

    private function eventByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_events WHERE event_hash=:hash');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function decodeEvent(array $row): array
    {
        $event = json_decode($this->decrypt((string) $row['event_data']), true);
        if (!is_array($event)) throw new RuntimeException('Stored calendar event is corrupt.');
        $event['_hash'] = (string) $row['event_hash'];
        $event['_fingerprint'] = (string) $row['fingerprint'];
        if (!empty($row['previous_event_data'])) {
            $previous = json_decode($this->decrypt((string) $row['previous_event_data']), true);
            if (is_array($previous)) $event['_previous'] = $previous;
        }
        return $event;
    }

    private function encrypt(string $value): string { return (new Crypto())->seal($value, $this->key); }
    private function decrypt(string $value): string { return (new Crypto())->open($value, $this->key); }
}
