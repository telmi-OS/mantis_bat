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
        $this->ghostClient->chat($text);
    }
}
