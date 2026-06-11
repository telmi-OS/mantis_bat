<?php

declare(strict_types=1);

namespace MantisBat;

final class InboxPoller
{
    public function __construct(
        private readonly Storage $storage,
        private readonly GhostClient $ghostClient,
        private readonly TelegramClient $telegramClient,
        private readonly MessageSplitter $splitter,
        private readonly ?Logger $logger = null
    ) {
    }

    public function run(): array
    {
        $owner = $this->storage->getAuthorizedOwner();
        if ($owner === null) {
            return ['ok' => true, 'delivered' => 0, 'reason' => 'no_owner'];
        }

        $chatId = (string) $owner['telegram_chat_id'];
        $response = $this->ghostClient->pullInbox();
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $delivered = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $messageId = (string) ($item['id'] ?? $item['message_id'] ?? '');
            $text = $this->normalizeText($item);
            if ($messageId === '' || $text === '') {
                continue;
            }

            if ($this->storage->hasDeliveredInboxMessage($messageId, $chatId)) {
                continue;
            }

            foreach ($this->splitter->split("Ghost inbox\n\n" . $text) as $chunk) {
                $this->telegramClient->sendMessage($chatId, $chunk);
            }

            $this->storage->markInboxDelivered($messageId, $chatId);
            $this->ghostClient->ackInbox($messageId);
            $this->storage->markInboxAcked($messageId, $chatId);
            $delivered++;
        }

        $this->logger?->info('Inbox poller run completed.', ['delivered' => $delivered]);

        return ['ok' => true, 'delivered' => $delivered];
    }

    private function normalizeText(array $item): string
    {
        foreach (['text', 'message', 'content', 'body'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
