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
        $job = $this->storage->nextPendingJob();
        if ($job === null) {
            return ['ok' => true, 'processed' => false, 'reason' => 'no_pending_job'];
        }

        $jobId = (int) $job['id'];
        $jobUuid = (string) $job['job_uuid'];
        $jobDir = $this->installer->jobsPath() . '/' . $jobUuid;
        if (!is_dir($jobDir) && !mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
            throw new RuntimeException('Could not create job directory.');
        }

        try {
            $this->storage->updateJobStatus($jobId, 'extracting');
            $files = $this->storage->listJobFiles($jobId);
            if ($files === []) {
                throw new RuntimeException('Job has no uploaded files.');
            }

            $documents = [];
            foreach ($files as $file) {
                $fileId = (int) $file['id'];
                $this->storage->updateJobFileStatus($fileId, 'extracting');
                $text = $this->extractor->extract((string) $file['stored_path'], (string) $file['extension']);
                $documents[] = [
                    'name' => (string) $file['original_name'],
                    'text' => $text,
                ];
                $this->storage->updateJobFileStatus($fileId, 'completed');
            }

            $normalizedText = $this->buildCombinedNormalizedText($documents);
            $inputCharCount = mb_strlen($normalizedText);
            $maxCharacters = (int) $this->config->get('limits.max_source_characters', 120000);
            if ($inputCharCount > $maxCharacters) {
                throw new RuntimeException(sprintf(
                    'Normalized document text is too large for one semantic Ghost pass (%d chars > %d chars). Split the job into smaller source files.',
                    $inputCharCount,
                    $maxCharacters
                ));
            }

            $sourcePath = $jobDir . '/normalized-source.txt';
            if (file_put_contents($sourcePath, $normalizedText) === false) {
                throw new RuntimeException('Could not save normalized source text.');
            }
            $this->storage->updateJobSource($jobId, $sourcePath, $inputCharCount);

            $this->storage->updateJobStatus($jobId, 'processing');
            $artifact = $this->ghostClient->processDocument((string) $job['title'], (string) ($job['guidance'] ?? ''), $normalizedText);
            $outputPath = $jobDir . '/telmi-os-rag-output.txt';
            if (file_put_contents($outputPath, $artifact) === false) {
                throw new RuntimeException('Could not save final RAG artifact.');
            }

            $this->storage->updateJobOutput($jobId, $outputPath, mb_strlen($artifact));
            $this->storage->updateJobStatus($jobId, 'completed');

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
            $this->storage->updateJobStatus($jobId, 'failed', $exception->getMessage());
            $this->logger?->exception($exception, [
                'job_id' => $jobId,
                'job_uuid' => $jobUuid,
            ]);

            return [
                'ok' => false,
                'processed' => true,
                'job_id' => $jobId,
                'job_uuid' => $jobUuid,
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ];
        }
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
