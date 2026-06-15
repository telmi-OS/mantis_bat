<?php

declare(strict_types=1);

namespace MantisBat;

final class ChatHandler
{
    public function __construct(
        private readonly GhostClient $ghostClient,
        private readonly TelegramClient $telegramClient,
        private readonly MessageSplitter $splitter
    ) {
    }

    public function handle(string $chatId, string $text): void
    {
        $response = $this->ghostClient->chat($text);

        // Some live Ghost runtimes return a usable reply inline even when mode=queued
        // and do not emit a later inbox item. In that case we deliver the inline reply.
        $status = trim((string) ($response['status'] ?? ''));
        $mode = trim((string) ($response['mode'] ?? ''));
        $jobId = trim((string) ($response['job_id'] ?? ''));
        $reply = $this->extractReply($response);

        if ($mode === 'queued' && $status === 'queued' && $jobId === '' && $reply !== '') {
            foreach ($this->splitter->split($reply) as $chunk) {
                $this->telegramClient->sendMessage($chatId, $chunk);
            }
        }
    }

    private function extractReply(array $response): string
    {
        foreach ([
            ['reply'],
            ['data', 'reply'],
            ['data', 'message'],
            ['data', 'text'],
        ] as $path) {
            $value = $this->readPath($response, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function readPath(array $payload, array $path): mixed
    {
        $value = $payload;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
