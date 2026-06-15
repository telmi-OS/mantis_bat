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
            return ['ok' => true, 'fetched' => 0, 'delivered' => 0, 'skipped' => 0, 'reason' => 'no_owner'];
        }

        $chatId = (string) $owner['telegram_chat_id'];
        $response = $this->ghostClient->pullInbox();
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $fetched = count($items);
        $delivered = 0;
        $skipped = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $messageId = $this->normalizeMessageId($item);
            $text = $this->normalizeText($item);
            if ($messageId === '' || $text === '') {
                $skipped++;
                $this->logger?->warning('Inbox item skipped because it did not contain a usable id or text.', ['item' => $item]);
                continue;
            }

            if ($this->storage->hasDeliveredInboxMessage($messageId, $chatId)) {
                $skipped++;
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

        $this->logger?->info('Inbox poller run completed.', ['fetched' => $fetched, 'delivered' => $delivered, 'skipped' => $skipped]);

        return ['ok' => true, 'fetched' => $fetched, 'delivered' => $delivered, 'skipped' => $skipped];
    }

    private function normalizeText(array $item): string
    {
        foreach ([
            ['text'],
            ['message'],
            ['content'],
            ['body'],
            ['reply'],
            ['data', 'reply'],
            ['data', 'text'],
            ['data', 'message'],
            ['payload', 'reply'],
            ['payload', 'text'],
            ['payload', 'message'],
        ] as $path) {
            $value = $this->readNestedString($item, $path);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeMessageId(array $item): string
    {
        foreach ([
            ['id'],
            ['message_id'],
            ['data', 'id'],
            ['data', 'message_id'],
            ['payload', 'id'],
            ['payload', 'message_id'],
        ] as $path) {
            $value = $this->readNestedScalar($item, $path);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function readNestedString(array $item, array $path): string
    {
        $value = $this->readPath($item, $path);
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function readNestedScalar(array $item, array $path): string
    {
        $value = $this->readPath($item, $path);
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function readPath(array $item, array $path): mixed
    {
        $value = $item;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
