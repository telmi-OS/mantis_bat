<?php

declare(strict_types=1);

namespace MantisBat;

use Throwable;

final class Logger
{
    public function __construct(
        private readonly Security $security,
        private readonly ?Storage $storage = null,
        private readonly ?string $filePath = null,
        private readonly array $knownSecrets = []
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function exception(Throwable $exception, array $context = []): void
    {
        $context['exception'] = [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        $this->write('error', 'Unhandled exception', $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $payload = [
            'message' => $this->security->redact($message, $this->knownSecrets),
            'context_json' => $this->security->redact((string) json_encode($context, JSON_UNESCAPED_SLASHES), $this->knownSecrets),
            'created_at' => time(),
        ];

        if ($this->storage !== null) {
            $this->storage->insertLog($level, $payload['message'], $payload['context_json'], $payload['created_at']);
        }

        if ($this->filePath !== null && $this->filePath !== '') {
            $line = sprintf("[%s] %s %s %s\n", date('c'), strtoupper($level), $payload['message'], $payload['context_json']);
            @file_put_contents($this->filePath, $line, FILE_APPEND);
        }
    }
}
