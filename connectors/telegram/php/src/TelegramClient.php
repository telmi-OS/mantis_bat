<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class TelegramClient
{
    public function __construct(
        private readonly string $botToken,
        private readonly ?Logger $logger = null
    ) {
    }

    public function getMe(): array
    {
        return $this->request('getMe');
    }

    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = ['url' => $url];
        if ($secretToken !== null && $secretToken !== '') {
            $payload['secret_token'] = $secretToken;
        }

        return $this->request('setWebhook', $payload);
    }

    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook');
    }

    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->request('sendMessage', array_merge($options, [
            'chat_id' => $chatId,
            'text' => $text,
        ]));
    }

    public function sendChatAction(string $chatId, string $action = 'typing'): array
    {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    public function parseUpdate(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [];
        }

        $message = $payload['message'] ?? $payload['edited_message'] ?? null;
        if (!is_array($message)) {
            return [
                'update_id' => (string) ($payload['update_id'] ?? ''),
                'unsupported' => true,
            ];
        }

        return [
            'update_id' => (string) ($payload['update_id'] ?? ''),
            'message_id' => (string) ($message['message_id'] ?? ''),
            'text' => isset($message['text']) ? trim((string) $message['text']) : '',
            'chat_id' => (string) ($message['chat']['id'] ?? ''),
            'from_id' => (string) ($message['from']['id'] ?? ''),
            'username' => (string) ($message['from']['username'] ?? ''),
            'first_name' => (string) ($message['from']['first_name'] ?? ''),
            'last_name' => (string) ($message['from']['last_name'] ?? ''),
            'message_type' => isset($message['text']) ? 'text' : 'unsupported',
            'raw' => $payload,
        ];
    }

    private function request(string $method, array $payload = []): array
    {
        $url = sprintf('https://api.telegram.org/bot%s/%s', $this->botToken, $method);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Telegram API request failed: ' . $error);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $this->logger?->warning('Telegram API returned non-ok response.', ['method' => $method, 'status' => $status, 'body' => $decoded ?: $body]);
            throw new RuntimeException('Telegram API request failed.');
        }

        return $decoded;
    }
}
