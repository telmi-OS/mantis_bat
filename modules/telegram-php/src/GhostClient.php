<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class GhostClient
{
    public function __construct(
        private readonly Config $config,
        private readonly ?Logger $logger = null
    ) {
    }

    public function chat(string $message, array $options = []): array
    {
        $payload = [
            'message' => $message,
            'meta' => [
                'source' => 'mantis_bat',
                'channel' => 'telegram',
            ],
        ];

        $groupId = $this->config->get('ghost.default_group_id', '');
        if ($groupId !== '') {
            $payload['group_id'] = $groupId;
        }

        if ($options !== []) {
            $payload['options'] = $options;
        }

        return $this->request('POST', (string) $this->config->get('ghost.paths.chat', '/chat'), $payload);
    }

    public function upsertMemory(string $text, array $metadata = []): array
    {
        $payload = [
            'items' => [[
                'text' => $text,
                'metadata' => $metadata,
            ]],
        ];

        $groupId = $this->config->get('ghost.default_group_id', '');
        if ($groupId !== '') {
            $payload['group_id'] = $groupId;
        }

        return $this->request('POST', (string) $this->config->get('ghost.paths.memory_upsert', '/memory/upsert'), $payload);
    }

    public function searchMemory(string $query): array
    {
        return $this->request('POST', (string) $this->config->get('ghost.paths.memory_search', '/memory/search'), [
            'query' => $query,
            'scope' => 'group',
            'group_id' => $this->config->get('ghost.default_group_id', ''),
        ]);
    }

    public function listMemory(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.memory_list', '/memory/list'), [], [
            'scope' => 'group',
            'group_id' => (string) $this->config->get('ghost.default_group_id', ''),
            'limit' => 20,
        ]);
    }

    public function deleteMemory(array $ids): array
    {
        return $this->request('POST', (string) $this->config->get('ghost.paths.memory_delete', '/memory/delete'), [
            'scope' => 'group',
            'group_id' => $this->config->get('ghost.default_group_id', ''),
            'ids' => array_values($ids),
        ]);
    }

    public function pullInbox(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.inbox', '/inbox'), [], [
            'group_id' => (string) $this->config->get('ghost.default_group_id', ''),
            'limit' => (int) $this->config->get('limits.cron_batch_size', 20),
        ]);
    }

    public function ackInbox(string $messageId): array
    {
        $payload = ['message_id' => $messageId];
        $groupId = $this->config->get('ghost.default_group_id', '');
        if ($groupId !== '') {
            $payload['group_id'] = $groupId;
        }

        return $this->request('POST', (string) $this->config->get('ghost.paths.inbox_ack', '/inbox/ack'), $payload);
    }

    public function readSettings(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.settings', '/settings'));
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $base = rtrim((string) $this->config->require('ghost.api_base'), '/');
        $url = $base . '/' . ltrim($path, '/');
        if ($query !== []) {
            $query = array_filter($query, static fn($value) => $value !== '');
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->config->require('ghost.api_token'),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Ghost API request failed: ' . $error);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->logger?->warning('Ghost API returned non-JSON response.', ['path' => $path, 'status' => $status, 'body' => $body]);
            throw new RuntimeException('Ghost API did not return valid JSON.');
        }

        if ($status >= 400 || (isset($decoded['ok']) && $decoded['ok'] === false)) {
            $this->logger?->warning('Ghost API returned an error response.', ['path' => $path, 'status' => $status, 'body' => $decoded]);
            throw new RuntimeException((string) ($decoded['error'] ?? 'Ghost API request failed.'));
        }

        return $decoded;
    }
}
