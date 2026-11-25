<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class JobProcessor
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly Storage $storage,
        private readonly GhostClient $ghostClient,
        private readonly DocumentExtractor $extractor,
        private readonly Installer $installer,
        private readonly ?Logger $logger = null
    ) {
    }

    public function processNext(): array
    {
        $notification = $this->storage->nextNotification();
        if ($notification !== null) {
            return $this->processNotification($notification);
        }
        $maxAttempts = min(3, max(1, (int) $this->config->get('limits.max_attempts', 3)));
        $retryDelays = $this->config->get('limits.retry_delays_seconds', [60, 300]);
        $firstRetryDelay = is_array($retryDelays) && isset($retryDelays[0]) ? max(0, (int) $retryDelays[0]) : 60;
        $staleJobSeconds = max(1, (int) $this->config->get('limits.stale_job_seconds', 60));
        $job = $this->storage->nextPendingJob($maxAttempts, $staleJobSeconds, $firstRetryDelay, $this->notificationsEnabled());
        if ($job === null) {
            return ['ok' => true, 'processed' => false, 'reason' => 'no_pending_job'];
        }

        $jobId = (int) $job['id'];
        $jobUuid = (string) $job['job_uuid'];
        $jobDir = $this->installer->jobsPath() . '/' . $jobUuid;
        if (!is_dir($jobDir) && !mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
            return $this->failJob($jobId, $jobUuid, new RuntimeException('Could not create job directory.'), $maxAttempts);
        }

        try {
            $this->storage->beginJobAttempt($jobId);
            $sourcePath = (string) ($job['source_text_path'] ?? '');
            $normalizedText = '';
            if ($sourcePath !== '' && is_file($sourcePath)) {
                $normalizedText = file_get_contents($sourcePath);
                if ($normalizedText === false) {
                    throw new RuntimeException('Could not read normalized source text.');
                }
            } else {
                $this->storage->updateJobStatus($jobId, 'extracting');
                $files = $this->storage->listJobFiles($jobId);
                if ($files === []) {
                    throw new RuntimeException('Job has no uploaded files.');
                }

                $documents = [];
                foreach ($files as $file) {
                    $fileId = (int) $file['id'];
                    $this->storage->updateJobFileStatus($fileId, 'extracting');
                    try {
                        $text = $this->extractor->extract((string) $file['stored_path'], (string) $file['extension']);
                    } catch (\Throwable $exception) {
                        $this->storage->updateJobFileStatus($fileId, 'failed', $exception->getMessage());
                        throw $exception;
                    }

                    $documents[] = [
                        'name' => (string) $file['original_name'],
                        'text' => $text,
                    ];
                    $this->storage->updateJobFileStatus($fileId, 'completed');
                }

                $normalizedText = $this->buildCombinedNormalizedText($documents);
            }

            if (trim($normalizedText) === '') {
                throw new RuntimeException('Document extraction produced no usable text.');
            }

            $inputCharCount = mb_strlen($normalizedText);
            $maxCharacters = (int) $this->config->get('limits.max_source_characters', 120000);
            if ($inputCharCount > $maxCharacters) {
                throw new RuntimeException(sprintf(
                    'Normalized document text is too large for one semantic Ghost pass (%d chars > %d chars). Split the job into smaller source files.',
                    $inputCharCount,
                    $maxCharacters
                ));
            }

            if (!is_file($sourcePath)) {
                $sourcePath = $jobDir . '/normalized-source.txt';
                if (file_put_contents($sourcePath, $normalizedText) === false) {
                    throw new RuntimeException('Could not save normalized source text.');
                }
                $this->storage->updateJobSource($jobId, $sourcePath, $inputCharCount);
            }

            $this->storage->updateJobStatus($jobId, 'processing');
            $artifact = $this->ghostClient->processDocument((string) $job['title'], (string) ($job['guidance'] ?? ''), $normalizedText);
            $outputPath = $jobDir . '/telmi-os-rag-output.txt';
            if (file_put_contents($outputPath, $artifact) === false) {
                throw new RuntimeException('Could not save final RAG artifact.');
            }

            $this->storage->updateJobOutput($jobId, $outputPath, mb_strlen($artifact));
            $this->storage->updateJobStatus($jobId, 'completed', null, $this->notificationsEnabled());

            $this->logger?->info('RAG prep job completed.', [
                'job_id' => $jobId,
                'job_uuid' => $jobUuid,
                'input_char_count' => $inputCharCount,
                'output_char_count' => mb_strlen($artifact),
            ]);

            return [
                'ok' => true,
                'processed' => true,
                'job_id' => $jobId,
                'job_uuid' => $jobUuid,
                'status' => 'completed',
                'input_char_count' => $inputCharCount,
                'output_char_count' => mb_strlen($artifact),
            ];
        } catch (\Throwable $exception) {
            return $this->failJob($jobId, $jobUuid, $exception, $maxAttempts, (int) ($job['attempt_count'] ?? 0) + 1);
        }
    }

    private function failJob(int $jobId, string $jobUuid, \Throwable $exception, int $maxAttempts, int $attemptNumber = 1): array
    {
        $status = 'failed';
        $nextAttemptAt = null;
        if ($exception instanceof RetryableException && $attemptNumber < $maxAttempts) {
            $delay = $this->retryDelayForAttempt($attemptNumber);
            $nextAttemptAt = time() + $delay;
            $this->storage->markRetryable($jobId, $exception->getMessage(), $nextAttemptAt);
            $status = 'retryable';
        } else {
            $this->storage->updateJobStatus($jobId, 'failed', $exception->getMessage(), $this->notificationsEnabled());
        }

        $this->logger?->exception($exception, [
            'job_id' => $jobId,
            'job_uuid' => $jobUuid,
            'attempt' => $attemptNumber,
            'status' => $status,
        ]);

        return [
            'ok' => false,
            'processed' => true,
            'job_id' => $jobId,
            'job_uuid' => $jobUuid,
            'status' => $status,
            'attempt' => $attemptNumber,
            'next_attempt_at' => $nextAttemptAt,
            'error' => $exception->getMessage(),
        ];
    }

    private function notificationsEnabled(): bool
    {
        return trim((string) $this->config->get('notifications.group_name', '')) !== '';
    }

    private function processNotification(array $job): array
    {
        $jobId = (int) $job['id'];
        $group = trim((string) $this->config->get('notifications.group_name', ''));
        if ($group === '') {
            $this->storage->updateNotification($jobId, 'failed', 'Notification destination is no longer configured.');
            return ['ok' => false, 'notification' => 'failed', 'job_id' => $jobId];
        }
        $this->storage->updateNotification($jobId, 'sending');
        try {
            $this->ghostClient->notifyGroup($group, (string) $job['title'], (string) $job['status']);
            $this->storage->updateNotification($jobId, 'requested');
            return ['ok' => true, 'notification' => 'requested', 'job_id' => $jobId];
        } catch (\Throwable $exception) {
            $this->storage->updateNotification($jobId, 'uncertain', 'Notification request failed or its delivery could not be confirmed. Check the group chat.');
            $this->logger?->exception($exception, ['job_id' => $jobId, 'operation' => 'notification']);
            return ['ok' => false, 'notification' => 'uncertain', 'job_id' => $jobId];
        }
    }

    private function retryDelayForAttempt(int $attemptNumber): int
    {
        $delays = $this->config->get('limits.retry_delays_seconds', [60, 300]);
        if (!is_array($delays)) {
            return 60;
        }

        return max(0, (int) ($delays[$attemptNumber - 1] ?? 60));
    }

    private function buildCombinedNormalizedText(array $documents): string
    {
        $parts = [];
        foreach ($documents as $document) {
            $name = trim((string) ($document['name'] ?? 'document'));
            $text = trim((string) ($document['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $parts[] = "Document: {$name}\n\n{$text}";
        }

        return trim(implode("\n\n", $parts));
    }
}
