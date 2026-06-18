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
        $status = trim((string) ($response['status'] ?? ''));
        $mode = trim((string) ($response['mode'] ?? ''));
        $jobId = trim((string) ($response['job_id'] ?? ''));
        $reply = $this->extractPrimaryReply($response);
        $systemMessages = $this->extractSystemMessages($response);

        if ($reply !== '' && !($mode === 'queued' && $status === 'queued' && $jobId !== '')) {
            $this->sendChunks($chatId, $reply);
        }

        foreach ($systemMessages as $systemMessage) {
            $this->sendChunks($chatId, "System\n\n" . $systemMessage);
        }
    }

    private function extractPrimaryReply(array $response): string
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

    private function extractSystemMessages(array $response): array
    {
        $messages = [];
        $primaryReply = $this->extractPrimaryReply($response);
        $status = trim((string) ($response['status'] ?? ''));
        $jobId = trim((string) ($response['job_id'] ?? ''));

        if ($status === 'queued' && $jobId !== '') {
            $messages[] = 'Message queued.';
        }

        foreach ([
            ['data', 'message'],
            ['data', 'text'],
            ['data', 'notice'],
            ['data', 'warning'],
            ['data', 'status_message'],
            ['data', 'system_message'],
            ['message'],
            ['notice'],
            ['warning'],
        ] as $path) {
            $value = $this->readPath($response, $path);
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || $value === $primaryReply) {
                continue;
            }
            $messages[] = $value;
        }

        return array_values(array_unique($messages));
    }

    private function sendChunks(string $chatId, string $text): void
    {
        foreach ($this->splitter->split($text) as $chunk) {
            $this->telegramClient->sendMessage($chatId, $chunk);
        }
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
