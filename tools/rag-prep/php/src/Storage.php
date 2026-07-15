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
            'CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_uuid TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                guidance TEXT,
                status TEXT NOT NULL,
                input_char_count INTEGER DEFAULT 0,
                output_char_count INTEGER DEFAULT 0,
                source_text_path TEXT,
                output_text_path TEXT,
                error_message TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                started_at INTEGER,
                completed_at INTEGER
            )',
            'CREATE TABLE IF NOT EXISTS job_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                original_name TEXT NOT NULL,
                stored_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                extension TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                extract_status TEXT NOT NULL DEFAULT \'pending\',
                extract_error TEXT,
                created_at INTEGER NOT NULL,
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, level TEXT NOT NULL, message TEXT NOT NULL, context_json TEXT, created_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS request_limits (bucket TEXT NOT NULL, subject TEXT NOT NULL, count INTEGER NOT NULL, reset_at INTEGER NOT NULL, PRIMARY KEY(bucket, subject))',
        ];

        foreach ($queries as $query) {
            $this->pdo->exec($query);
        }

        $this->pdo->exec('PRAGMA foreign_keys = ON');
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

    public function createJob(string $jobUuid, string $title, string $guidance): int
    {
        $now = time();
        $stmt = $this->pdo->prepare('INSERT INTO jobs (job_uuid, title, guidance, status, created_at, updated_at) VALUES (:job_uuid, :title, :guidance, :status, :created_at, :updated_at)');
        $stmt->execute([
            ':job_uuid' => $jobUuid,
            ':title' => $title,
            ':guidance' => $guidance,
            ':status' => 'uploaded',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addJobFile(int $jobId, string $originalName, string $storedPath, string $mimeType, string $extension, int $sizeBytes): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO job_files (job_id, original_name, stored_path, mime_type, extension, size_bytes, created_at) VALUES (:job_id, :original_name, :stored_path, :mime_type, :extension, :size_bytes, :created_at)');
        $stmt->execute([
            ':job_id' => $jobId,
            ':original_name' => $originalName,
            ':stored_path' => $storedPath,
            ':mime_type' => $mimeType,
            ':extension' => $extension,
            ':size_bytes' => $sizeBytes,
            ':created_at' => time(),
        ]);
    }

    public function listJobs(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('
            SELECT j.*, COUNT(f.id) AS file_count
            FROM jobs j
            LEFT JOIN job_files f ON f.job_id = j.id
            GROUP BY j.id
            ORDER BY j.created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getJobByUuid(string $jobUuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE job_uuid = :job_uuid LIMIT 1');
        $stmt->execute([':job_uuid' => $jobUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listJobFiles(int $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM job_files WHERE job_id = :job_id ORDER BY id ASC');
        $stmt->execute([':job_id' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function nextPendingJob(): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM jobs WHERE status = 'uploaded' ORDER BY created_at ASC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateJobStatus(int $jobId, string $status, ?string $errorMessage = null): void
    {
        $now = time();
        $stmt = $this->pdo->prepare('UPDATE jobs SET status = :status, error_message = :error_message, updated_at = :updated_at, started_at = CASE WHEN :status = \'extracting\' AND started_at IS NULL THEN :updated_at ELSE started_at END, completed_at = CASE WHEN :status IN (\'completed\', \'failed\') THEN :updated_at ELSE completed_at END WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':updated_at' => $now,
            ':id' => $jobId,
        ]);
    }

    public function updateJobFileStatus(int $fileId, string $status, ?string $extractError = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE job_files SET extract_status = :status, extract_error = :extract_error WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':extract_error' => $extractError,
            ':id' => $fileId,
        ]);
    }

    public function updateJobArtifacts(int $jobId, string $sourceTextPath, int $inputCharCount, string $outputTextPath, int $outputCharCount): void
    {
        $stmt = $this->pdo->prepare('UPDATE jobs SET source_text_path = :source_text_path, input_char_count = :input_char_count, output_text_path = :output_text_path, output_char_count = :output_char_count, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':source_text_path' => $sourceTextPath,
            ':input_char_count' => $inputCharCount,
            ':output_text_path' => $outputTextPath,
            ':output_char_count' => $outputCharCount,
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function updateJobSource(int $jobId, string $sourceTextPath, int $inputCharCount): void
    {
        $stmt = $this->pdo->prepare('UPDATE jobs SET source_text_path = :source_text_path, input_char_count = :input_char_count, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':source_text_path' => $sourceTextPath,
            ':input_char_count' => $inputCharCount,
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function updateJobOutput(int $jobId, string $outputTextPath, int $outputCharCount): void
    {
        $stmt = $this->pdo->prepare('UPDATE jobs SET output_text_path = :output_text_path, output_char_count = :output_char_count, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':output_text_path' => $outputTextPath,
            ':output_char_count' => $outputCharCount,
            ':updated_at' => time(),
            ':id' => $jobId,
        ]);
    }

    public function resetJobs(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM job_files');
            $this->pdo->exec('DELETE FROM jobs');
            $this->commitTransaction();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deleteCompletedJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE status = 'completed'");
    }

    public function deleteFailedJobs(): void
    {
        $this->pdo->exec("DELETE FROM jobs WHERE status = 'failed'");
    }

    public function countJobs(): array
    {
        $statuses = ['uploaded', 'extracting', 'processing', 'completed', 'failed'];
        $counts = [];
        foreach ($statuses as $status) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE status = :status');
            $stmt->execute([':status' => $status]);
            $counts[$status] = (int) $stmt->fetchColumn();
        }

        return $counts;
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

    private function commitTransaction(): void
    {
        $this->pdo->commit();
    }
}
