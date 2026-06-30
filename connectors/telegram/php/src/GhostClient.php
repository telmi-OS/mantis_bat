<?php

declare(strict_types=1);

namespace MantisBat;

use RuntimeException;

final class GhostClient
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly ?Logger $logger = null
    ) {
    }

    public function chat(string $message, array $options = []): array
    {
        $options['mode'] = 'queued';
        $options['use_history'] = true;

        $payload = [
            'message' => $message,
            'mode' => 'queued',
            'history' => true,
            'use_history' => true,
            'meta' => [
                'source' => 'mantis_bat',
                'channel' => 'telegram',
            ],
            'options' => $options,
        ];

        $groupId = $this->config->get('ghost.default_group_id', '');
        if ($groupId !== '') {
            $payload['group_id'] = $groupId;
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

    public function pullInbox(?string $groupId = null): array
    {
        $query = [
            'limit' => (int) $this->config->get('limits.cron_batch_size', 50),
        ];
        if ($groupId !== null && trim($groupId) !== '') {
            $query['group_id'] = trim($groupId);
        }

        return $this->request('GET', (string) $this->config->get('ghost.paths.inbox', '/inbox'), [], $query);
    }

    public function pullInboxGroups(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.inbox_groups', '/inbox_groups'), [], [
            'limit' => (int) $this->config->get('limits.cron_batch_size', 50),
        ]);
    }

    public function readSettings(): array
    {
        return $this->request('GET', (string) $this->config->get('ghost.paths.settings', '/settings'));
    }

    public function probeSettings(): array
    {
        return $this->rawRequest('GET', (string) $this->config->get('ghost.paths.settings', '/settings'));
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $result = $this->rawRequest($method, $path, $payload, $query);
        $body = $result['body'];
        $status = $result['status'];
        $url = $result['url'];
        $contentType = $result['content_type'];

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $preview = $this->previewBody($body);
            $this->logger?->warning('Ghost API returned non-JSON response.', [
                'path' => $path,
                'url' => $url,
                'status' => $status,
                'content_type' => $contentType,
                'body_preview' => $preview,
            ]);
            throw new RuntimeException(sprintf(
                'Ghost API returned non-JSON response. URL: %s Status: %d Content-Type: %s Preview: %s',
                $url,
                $status,
                $contentType !== '' ? $contentType : 'unknown',
                $preview
            ));
        }

        if ($status >= 400 || (isset($decoded['ok']) && $decoded['ok'] === false)) {
            $this->logger?->warning('Ghost API returned an error response.', ['path' => $path, 'url' => $url, 'status' => $status, 'body' => $decoded]);
            $errorMessage = (string) ($decoded['error'] ?? 'Ghost API request failed.');
            throw new RuntimeException(sprintf('Ghost API error. URL: %s Status: %d Error: %s', $url, $status, $errorMessage));
        }

        return $decoded;
    }

    private function rawRequest(string $method, string $path, array $payload = [], array $query = []): array
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
            CURLOPT_HEADER => true,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Ghost API request failed: ' . $error);
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'url' => $url,
            'status' => $status,
            'headers' => $rawHeaders,
            'body' => $body,
            'content_type' => $this->extractContentType($rawHeaders),
        ];
    }

    private function extractContentType(string $rawHeaders): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) ?: [] as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                return trim(substr($line, strlen('Content-Type:')));
            }
        }

        return '';
    }

    private function previewBody(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if ($body === '') {
            return '[empty body]';
        }

        return mb_substr($body, 0, 220);
    }

    public function preview(string $body): string
    {
        return $this->previewBody($body);
    }
}
