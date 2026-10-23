<?php

declare(strict_types=1);

namespace MantisBat\GhostEval;

use PDO;
use RuntimeException;

final class Storage
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create private storage directory.');
        }
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS runs (
            id TEXT PRIMARY KEY,
            suite_file_id TEXT NOT NULL,
            suite_name TEXT NOT NULL,
            suite_sha256 TEXT NOT NULL,
            suite_json TEXT NOT NULL,
            settings_json TEXT NOT NULL,
            results_json TEXT NOT NULL,
            status TEXT NOT NULL,
            phase TEXT NOT NULL,
            case_index INTEGER NOT NULL DEFAULT 0,
            report_name TEXT NOT NULL DEFAULT "",
            report_uploaded INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            finished_at TEXT NOT NULL DEFAULT ""
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT NOT NULL,
            subject TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_status_created ON runs(status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_bucket_subject_time ON rate_limits(bucket, subject, created_at)');
        @chmod($this->path, 0600);
    }

    public function activeRunExists(): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM runs WHERE status IN ('queued','running')")->fetchColumn() > 0;
    }

    public function createRun(array $run): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($this->activeRunExists()) {
                throw new RuntimeException('A run is already queued or running. Wait for it to finish before starting another.');
            }
            $stmt = $this->pdo->prepare('INSERT INTO runs
                (id,suite_file_id,suite_name,suite_sha256,suite_json,settings_json,results_json,status,phase,case_index,created_at,updated_at)
                VALUES (:id,:file,:name,:sha,:suite,:settings,:results,:status,:phase,0,:created,:updated)');
            $stmt->execute([
                ':id' => $run['id'], ':file' => $run['suite_file_id'], ':name' => $run['suite_name'],
                ':sha' => $run['suite_sha256'], ':suite' => $run['suite_json'],
                ':settings' => json_encode($run['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':results' => json_encode($run['results'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':status' => 'queued', ':phase' => $run['phase'], ':created' => $run['created_at'], ':updated' => $run['created_at'],
            ]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    public function nextRun(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM runs WHERE status IN ('queued','running') ORDER BY created_at ASC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function getRun(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM runs WHERE id=:id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function saveRun(array $run): void
    {
        $stmt = $this->pdo->prepare('UPDATE runs SET settings_json=:settings,results_json=:results,status=:status,phase=:phase,
            case_index=:case_index,report_name=:report_name,report_uploaded=:report_uploaded,error=:error,updated_at=:updated,finished_at=:finished WHERE id=:id');
        $stmt->execute([
            ':settings' => json_encode($run['settings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':results' => json_encode($run['results'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':status' => $run['status'], ':phase' => $run['phase'], ':case_index' => $run['case_index'],
            ':report_name' => $run['report_name'], ':report_uploaded' => $run['report_uploaded'] ? 1 : 0,
            ':error' => $run['error'], ':updated' => $run['updated_at'], ':finished' => $run['finished_at'], ':id' => $run['id'],
        ]);
    }

    public function listRuns(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM runs ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', min(100, max(1, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn(array $row): array => $this->normalize($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function hitRateLimit(string $bucket, string $subject, int $maximum, int $periodSeconds): bool
    {
        $now = time();
        $prune = $this->pdo->prepare('DELETE FROM rate_limits WHERE created_at < :cutoff');
        $prune->execute([':cutoff' => $now - max(60, $periodSeconds * 4)]);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket=:bucket AND subject=:subject AND created_at>=:cutoff');
        $count->execute([':bucket' => $bucket, ':subject' => $subject, ':cutoff' => $now - $periodSeconds]);
        if ((int) $count->fetchColumn() >= $maximum) return true;
        $insert = $this->pdo->prepare('INSERT INTO rate_limits(bucket,subject,created_at) VALUES(:bucket,:subject,:now)');
        $insert->execute([':bucket' => $bucket, ':subject' => $subject, ':now' => $now]);
        return false;
    }

    public function resetRuns(): void
    {
        $this->pdo->exec('DELETE FROM runs');
        $this->pdo->exec('DELETE FROM rate_limits');
    }

    public function counts(): array
    {
        $rows = $this->pdo->query('SELECT status,COUNT(*) AS total FROM runs GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['queued' => 0, 'running' => 0, 'completed' => 0, 'completed_with_errors' => 0, 'failed' => 0];
        foreach ($rows as $row) $counts[(string) $row['status']] = (int) $row['total'];
        return $counts;
    }

    private function normalize(array $row): array
    {
        $row['suite'] = json_decode((string) $row['suite_json'], true) ?: [];
        $row['settings'] = json_decode((string) $row['settings_json'], true) ?: [];
        $row['results'] = json_decode((string) $row['results_json'], true) ?: [];
        $row['report_uploaded'] = (bool) $row['report_uploaded'];
        $row['case_index'] = (int) $row['case_index'];
        return $row;
    }
}
