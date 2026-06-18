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
            return ['ok' => true, 'fetched' => 0, 'fetched_groups' => 0, 'delivered' => 0, 'skipped' => 0, 'reason' => 'no_owner'];
        }

        $chatId = (string) $owner['telegram_chat_id'];
        $personalResponse = $this->ghostClient->pullInbox();
        $groupResponse = $this->ghostClient->pullInboxGroups();
        $messages = [
            ...$this->extractInboxMessages($personalResponse, false),
            ...$this->extractInboxMessages($groupResponse, true),
        ];

        $fetched = count($personalResponse['data']['items'] ?? $personalResponse['items'] ?? []);
        $fetchedGroups = count($groupResponse['data']['items'] ?? $groupResponse['items'] ?? []);
        $delivered = 0;
        $skipped = 0;

        foreach ($messages as $message) {
            $deliveryKey = (string) ($message['delivery_key'] ?? '');
            $ackMessageId = (string) ($message['ack_message_id'] ?? '');
            $ackGroupId = (string) ($message['ack_group_id'] ?? '');
            $text = (string) ($message['text'] ?? '');
            $isSystem = (bool) ($message['is_system'] ?? false);
            $isGroupMessage = (bool) ($message['is_group_message'] ?? false);
            $groupLabel = trim((string) ($message['group_label'] ?? ''));

            if ($deliveryKey === '' || $ackMessageId === '' || $text === '') {
                $skipped++;
                continue;
            }

            if ($this->storage->hasDeliveredInboxMessage($deliveryKey, $chatId)) {
                $skipped++;
                continue;
            }

            if ($isSystem) {
                $telegramText = "System\n\n" . $text;
            } elseif ($isGroupMessage) {
                $telegramText = $groupLabel !== '' ? $groupLabel . "\n\n" . $text : $text;
            } else {
                $telegramText = $text;
            }
            foreach ($this->splitter->split($telegramText) as $chunk) {
                $this->telegramClient->sendMessage($chatId, $chunk);
            }

            $this->storage->markInboxDelivered($deliveryKey, $chatId);
            if ($ackGroupId !== '') {
                $this->ghostClient->ackInbox($ackMessageId, $ackGroupId);
            } else {
                $this->ghostClient->ackInbox($ackMessageId);
            }
            $this->storage->markInboxAcked($deliveryKey, $chatId);
            $delivered++;
        }

        $this->logger?->info('Inbox poller run completed.', [
            'fetched' => $fetched,
            'fetched_groups' => $fetchedGroups,
            'delivered' => $delivered,
            'skipped' => $skipped,
        ]);

        return ['ok' => true, 'fetched' => $fetched, 'fetched_groups' => $fetchedGroups, 'delivered' => $delivered, 'skipped' => $skipped];
    }

    private function extractInboxMessages(array $response, bool $isGroupResponse): array
    {
        $messages = [];
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $fallbackGroupId = $isGroupResponse ? (string) ($item['group_id'] ?? '') : '';
                    $fallbackGroupLabel = $isGroupResponse ? $this->extractGroupLabel($item, '') : '';
                    $message = $this->normalizeInboxMessage($item, $fallbackGroupId, $fallbackGroupLabel);
                    if ($message !== null) {
                        $messages[] = $message;
                    }
                }
            }
        }

        $groups = $response['data']['groups'] ?? $response['groups'] ?? [];
        if (is_array($groups)) {
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $groupId = trim((string) ($group['group_id'] ?? ''));
                $groupItems = $group['items'] ?? [];
                if (!is_array($groupItems)) {
                    continue;
                }
                $groupLabel = $this->extractGroupLabel($group, $groupId);
                foreach ($groupItems as $item) {
                    if (is_array($item)) {
                        $message = $this->normalizeInboxMessage($item, $groupId, $groupLabel);
                        if ($message !== null) {
                            $messages[] = $message;
                        }
                    }
                }
            }
        }

        return $this->dedupeMessages($messages);
    }

    private function normalizeInboxMessage(array $item, string $fallbackGroupId = '', string $fallbackGroupLabel = ''): ?array
    {
        $ackMessageId = $this->normalizeMessageId($item);
        $groupId = trim((string) ($item['group_id'] ?? $fallbackGroupId));
        $groupLabel = $this->extractGroupLabel($item, $fallbackGroupLabel !== '' ? $fallbackGroupLabel : $groupId);
        $text = $this->normalizeText($item);

        if ($ackMessageId === '' || $text === '') {
            $this->logger?->warning('Inbox item skipped because it did not contain a usable id or text.', ['item' => $item]);
            return null;
        }

        return [
            'delivery_key' => ($groupId !== '' ? 'group:' . $groupId . ':' : 'ghost:') . $ackMessageId,
            'ack_message_id' => $ackMessageId,
            'ack_group_id' => $groupId,
            'text' => $text,
            'is_system' => $this->isSystemMessage($item),
            'is_group_message' => $groupId !== '',
            'group_label' => $groupLabel,
        ];
    }

    private function extractGroupLabel(array $item, string $fallback = ''): string
    {
        foreach ([
            ['group_name'],
            ['group_title'],
            ['group_label'],
            ['name'],
            ['title'],
            ['group', 'name'],
            ['group', 'title'],
            ['group', 'label'],
            ['data', 'group_name'],
            ['data', 'group_title'],
            ['data', 'group_label'],
            ['data', 'group', 'name'],
            ['data', 'group', 'title'],
            ['payload', 'group_name'],
            ['payload', 'group_title'],
            ['payload', 'group_label'],
            ['payload', 'group', 'name'],
            ['payload', 'group', 'title'],
        ] as $path) {
            $value = $this->readNestedString($item, $path);
            if ($value !== '') {
                return $value;
            }
        }

        return trim($fallback);
    }

    private function dedupeMessages(array $messages): array
    {
        $seen = [];
        $deduped = [];
        foreach ($messages as $message) {
            $key = (string) ($message['delivery_key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $message;
        }

        return $deduped;
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

    private function isSystemMessage(array $item): bool
    {
        foreach ([
            ['type'],
            ['kind'],
            ['role'],
            ['source'],
            ['data', 'type'],
            ['data', 'kind'],
            ['data', 'role'],
            ['payload', 'type'],
            ['payload', 'kind'],
            ['payload', 'role'],
        ] as $path) {
            $value = mb_strtolower($this->readNestedString($item, $path));
            if (in_array($value, ['system', 'notice', 'warning', 'info', 'ack', 'queued', 'status'], true)) {
                return true;
            }
        }

        return false;
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
