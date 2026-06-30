<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class InboxPoller
{
    public function __construct(
        private readonly Storage $storage,
        private readonly RuntimeConfig $config,
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
            return ['ok' => true, 'fetched' => 0, 'fetched_groups' => 0, 'seeded' => 0, 'ingested' => 0, 'delivered' => 0, 'reason' => 'no_owner'];
        }

        $chatId = (string) $owner['telegram_chat_id'];
        $fetchedAt = time();

        $personalResponse = null;
        $groupResponse = null;
        $fetchErrors = [];

        try {
            $personalResponse = $this->ghostClient->pullInbox();
        } catch (\Throwable $exception) {
            $fetchErrors['personal'] = $exception->getMessage();
            $this->logger?->warning('Personal Ghost inbox poll failed.', ['error' => $exception->getMessage()]);
        }

        try {
            $groupResponse = $this->ghostClient->pullInboxGroups();
        } catch (\Throwable $exception) {
            $fetchErrors['group_merged'] = $exception->getMessage();
            $this->logger?->warning('Merged Ghost group inbox poll failed.', ['error' => $exception->getMessage()]);
        }

        if ($personalResponse === null && $groupResponse === null) {
            throw new RuntimeException('All Ghost inbox polls failed.');
        }

        $personalMessages = $personalResponse !== null ? $this->extractPersonalMessages($personalResponse, $fetchedAt) : [];
        $groupMessages = $groupResponse !== null ? $this->extractGroupMessages($groupResponse, $fetchedAt) : [];
        $fetched = count($personalMessages);
        $fetchedGroups = count($groupMessages);
        $allMessages = $this->dedupeMessages([...$personalMessages, ...$groupMessages]);

        $seeded = 0;
        $ingested = 0;
        $delivered = 0;
        foreach ($allMessages as $message) {
            $messageKey = (string) ($message['message_key'] ?? '');
            $scopeKey = (string) ($message['scope_key'] ?? '');
            if ($messageKey === '' || $scopeKey === '' || $this->storage->hasInboxBackendMessage($messageKey)) {
                continue;
            }

            if (!$this->isScopeInitialized($scopeKey)) {
                $this->storage->insertInboxBackendMessage($message, true, false);
                $seeded++;
                continue;
            }

            $this->storage->insertInboxBackendMessage($message);
            $ingested++;
        }

        $this->markSeenScopesInitialized($allMessages);

        $pendingMessages = $this->storage->listPendingInboxBackendMessages();
        foreach ($pendingMessages as $message) {
            $this->sendTelegramMessage($chatId, $message);

            $messageKey = (string) ($message['message_key'] ?? '');
            if ($messageKey !== '') {
                $this->storage->markInboxBackendDelivered($messageKey);
                $delivered++;
            }
        }

        $this->logger?->info('Inbox poller run completed.', [
            'fetched' => $fetched,
            'fetched_groups' => $fetchedGroups,
            'seeded' => $seeded,
            'ingested' => $ingested,
            'delivered' => $delivered,
            'errors' => $fetchErrors,
        ]);

        return [
            'ok' => true,
            'fetched' => $fetched,
            'fetched_groups' => $fetchedGroups,
            'seeded' => $seeded,
            'ingested' => $ingested,
            'delivered' => $delivered,
            'errors' => $fetchErrors,
        ];
    }

    private function extractPersonalMessages(array $response, int $fetchedAt): array
    {
        $messages = [];
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $total = count($items);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceOrder = $total - (is_int($index) ? $index : 0);
            $senderLabel = $this->extractPersonalSenderLabel($item);
            $message = $this->normalizeMessage($item, 'personal', '', $senderLabel, $fetchedAt, $sourceOrder);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function extractGroupMessages(array $response, int $fetchedAt): array
    {
        $messages = [];
        $items = $response['data']['items'] ?? $response['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        $total = count($items);

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $groupId = trim((string) ($item['group_id'] ?? $item['polled_group_id'] ?? ''));
            $groupLabel = $this->extractGroupLabel($item, $groupId);
            $sourceOrder = $total - (is_int($index) ? $index : 0);
            $message = $this->normalizeMessage($item, 'group_merged', $groupId, $groupLabel, $fetchedAt, $sourceOrder);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function normalizeMessage(
        array $item,
        string $source,
        string $groupId,
        string $groupLabel,
        int $fetchedAt,
        int $sourceIndex
    ): ?array {
        $messageId = $this->normalizeMessageId($item);
        $text = $this->normalizeText($item);
        if ($messageId === '' || $text === '') {
            $this->logger?->warning('Inbox item skipped because it did not contain a usable id or text.', ['item' => $item, 'source' => $source]);
            return null;
        }

        $timestamp = $this->normalizeTimestamp($item);
        $messageKey = $source . ':' . ($groupId !== '' ? $groupId . ':' : '') . $messageId;

        return [
            'source' => $source,
            'scope_key' => $this->scopeKey($source, $groupId),
            'message_key' => $messageKey,
            'message_id' => $messageId,
            'group_id' => $groupId,
            'group_label' => $groupLabel,
            'text' => $text,
            'is_system' => $this->isSystemMessage($item),
            'sort_ts' => $timestamp['iso'],
            'sort_unix' => $timestamp['unix'],
            'fetched_at' => ($fetchedAt * 1000) + $sourceIndex,
        ];
    }

    private function sendTelegramMessage(string $chatId, array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $source = trim((string) ($message['source'] ?? 'personal'));
        $label = trim((string) ($message['group_label'] ?? ''));
        $isSystem = !empty($message['is_system']);

        if ($text === '') {
            return;
        }

        if ($isSystem) {
            foreach ($this->splitter->split("System\n\n" . $text) as $chunk) {
                $this->telegramClient->sendMessage($chatId, $chunk);
            }
            return;
        }

        $emoji = '';
        $labelPrefix = '';
        if ($source === 'group_merged' && $label !== '') {
            $emoji = "\u{1F465}";
            $labelPrefix = 'For Group ';
        }
        if ($source === 'personal' && $label !== '') {
            $emoji = "\u{1F464}";
            $labelPrefix = 'FROM: ';
        }

        $chunks = $this->splitter->split($text);
        foreach ($chunks as $index => $chunk) {
            if ($index === 0 && $emoji !== '' && $label !== '') {
                $displayLabel = $this->capitalizeLabel($label);
                $formatted = sprintf(
                    '%s <b>%s</b>%s%s',
                    $emoji,
                    htmlspecialchars($labelPrefix . $displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    "\n\n",
                    htmlspecialchars($chunk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
                $this->telegramClient->sendMessage($chatId, $formatted, ['parse_mode' => 'HTML']);
                continue;
            }

            $this->telegramClient->sendMessage($chatId, $chunk);
        }
    }

    private function capitalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    private function extractPersonalSenderLabel(array $item): string
    {
        $senderId = $this->readNestedString($item, ['from_user_id']);
        if ($senderId === '') {
            $senderId = $this->readNestedString($item, ['data', 'from_user_id']);
        }
        if ($senderId === '') {
            $senderId = $this->readNestedString($item, ['payload', 'from_user_id']);
        }

        $normalized = mb_strtolower(trim($senderId));
        if ($normalized === '' || $normalized === 'telmi' || $normalized === 'lakshmi') {
            return '';
        }

        foreach ([
            ['from_display_name'],
            ['data', 'from_display_name'],
            ['payload', 'from_display_name'],
        ] as $path) {
            $displayName = $this->readNestedString($item, $path);
            if ($displayName !== '') {
                return $displayName;
            }
        }

        return trim($senderId);
    }

    private function isScopeInitialized(string $scopeKey): bool
    {
        return $this->storage->getSetting($this->scopeSettingKey($scopeKey), '0') === '1';
    }

    private function markSeenScopesInitialized(array $messages): void
    {
        $seen = [];
        foreach ($messages as $message) {
            $scopeKey = (string) ($message['scope_key'] ?? '');
            if ($scopeKey === '' || isset($seen[$scopeKey])) {
                continue;
            }
            $seen[$scopeKey] = true;
            if (!$this->isScopeInitialized($scopeKey)) {
                $this->storage->setSetting($this->scopeSettingKey($scopeKey), '1');
            }
        }
    }

    private function scopeKey(string $source, string $groupId): string
    {
        if ($source === 'group_merged') {
            return 'group:' . ($groupId !== '' ? $groupId : 'unknown');
        }

        return 'personal';
    }

    private function scopeSettingKey(string $scopeKey): string
    {
        return 'inbox_scope_initialized:' . $scopeKey;
    }

    private function dedupeMessages(array $messages): array
    {
        $seen = [];
        $deduped = [];
        foreach ($messages as $message) {
            $key = (string) ($message['message_key'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $message;
        }

        return $deduped;
    }

    private function extractGroupLabel(array $item, string $fallback = ''): string
    {
        foreach ([
            ['group_display_name'],
            ['group_name'],
            ['group_title'],
            ['group_label'],
            ['name'],
            ['title'],
            ['data', 'group_display_name'],
            ['group', 'name'],
            ['group', 'title'],
            ['group', 'label'],
            ['payload', 'group_display_name'],
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

    private function normalizeText(array $item): string
    {
        foreach ([
            ['text'],
            ['message'],
            ['content'],
            ['body'],
            ['reply'],
            ['notice'],
            ['warning'],
            ['status_message'],
            ['system_message'],
            ['acknowledgement'],
            ['ack'],
            ['data', 'reply'],
            ['data', 'text'],
            ['data', 'message'],
            ['data', 'notice'],
            ['data', 'warning'],
            ['data', 'status_message'],
            ['data', 'system_message'],
            ['data', 'acknowledgement'],
            ['data', 'ack'],
            ['payload', 'reply'],
            ['payload', 'text'],
            ['payload', 'message'],
            ['payload', 'notice'],
            ['payload', 'warning'],
            ['payload', 'status_message'],
            ['payload', 'system_message'],
            ['payload', 'acknowledgement'],
            ['payload', 'ack'],
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

        foreach ([
            ['notice'],
            ['warning'],
            ['status_message'],
            ['system_message'],
            ['acknowledgement'],
            ['ack'],
            ['data', 'notice'],
            ['data', 'warning'],
            ['data', 'status_message'],
            ['data', 'system_message'],
            ['data', 'acknowledgement'],
            ['data', 'ack'],
            ['payload', 'notice'],
            ['payload', 'warning'],
            ['payload', 'status_message'],
            ['payload', 'system_message'],
            ['payload', 'acknowledgement'],
            ['payload', 'ack'],
        ] as $path) {
            if ($this->readNestedString($item, $path) !== '') {
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
            ['uuid'],
            ['data', 'id'],
            ['data', 'message_id'],
            ['data', 'uuid'],
            ['payload', 'id'],
            ['payload', 'message_id'],
            ['payload', 'uuid'],
        ] as $path) {
            $value = $this->readNestedScalar($item, $path);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeTimestamp(array $item): array
    {
        foreach ([
            ['created_at'],
            ['message_ts'],
            ['timestamp'],
            ['ts'],
            ['sent_at'],
            ['published_at'],
            ['data', 'created_at'],
            ['data', 'message_ts'],
            ['data', 'timestamp'],
            ['data', 'ts'],
            ['payload', 'created_at'],
            ['payload', 'message_ts'],
            ['payload', 'timestamp'],
            ['payload', 'ts'],
        ] as $path) {
            $value = $this->readPath($item, $path);
            $normalized = $this->parseTimestamp($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return ['iso' => null, 'unix' => null];
    }

    private function parseTimestamp(mixed $value): ?array
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            $unix = (int) $value;
            if ($unix > 0) {
                return ['iso' => gmdate('c', $unix), 'unix' => $unix];
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $unix = strtotime($value);
        if ($unix === false || $unix <= 0) {
            return null;
        }

        return ['iso' => gmdate('c', $unix), 'unix' => $unix];
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
