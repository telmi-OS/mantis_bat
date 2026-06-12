<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

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
        $this->telegramClient->sendChatAction($chatId, 'typing');
        $response = $this->ghostClient->chat($text);
        $reply = trim((string) ($response['reply'] ?? ''));

        if ($reply === '') {
            throw new RuntimeException('Ghost API did not return a reply.');
        }

        foreach ($this->splitter->split($reply) as $chunk) {
            $this->telegramClient->sendMessage($chatId, $chunk);
        }
    }
}
