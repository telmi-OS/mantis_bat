<?php

declare(strict_types=1);

namespace MantisBat\GhostEval;

use RuntimeException;

final class GhostClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $token)
    {
        if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL is required.');
    }

    public function chat(string $message, string $groupId, bool $useRag, bool $useHistory, array $meta = []): string
    {
        $requestId = bin2hex(random_bytes(12));
        $payload = [
            'group_id' => $groupId,
            'message' => $message,
            'message_id' => 'ghost-eval-' . $requestId,
            'message_ts' => date(DATE_ATOM),
            'mode' => 'realtime',
            'use_history' => $useHistory,
            'meta' => array_merge(['source' => 'mantis_bat_ghost_eval', 'request_id' => $requestId], $meta),
            'options' => ['mode' => 'realtime', 'use_rag' => $useRag, 'use_history' => $useHistory],
        ];
        $body = $this->requestJson('POST', '/chat', $payload);
        $reply = $body['reply'] ?? $body['data']['reply'] ?? null;
        if (!is_string($reply) || trim($reply) === '') {
            throw new RuntimeException('Ghost API returned no realtime reply.');
        }
        return trim($reply);
    }

    public function groupSpaces(): array
    {
        $body = $this->requestJson('GET', '/files?action=spaces');
        $spaces = $body['data']['spaces'] ?? [];
        if (!is_array($spaces)) throw new RuntimeException('Ghost Files API returned an invalid spaces list.');
        return array_values(array_filter($spaces, static fn($space): bool => is_array($space)
            && ($space['identity_type'] ?? '') === 'group'
            && is_string($space['group_id'] ?? null)
            && in_array(($space['group_role'] ?? ''), ['owner', 'write'], true)));
    }

    public function listFiles(string $spaceId, string $folderId = ''): array
    {
        $query = ['action' => 'list', 'space_id' => $spaceId, 'limit' => 200];
        if ($folderId !== '') $query['folder_id'] = $folderId;
        $body = $this->requestJson('GET', '/files?' . http_build_query($query));
        $files = $body['data']['files'] ?? [];
        if (!is_array($files)) throw new RuntimeException('Ghost Files API returned an invalid file list.');
        return array_values(array_filter($files, 'is_array'));
    }

    public function fileContent(string $fileId): string
    {
        $url = $this->url('/files?' . http_build_query(['action' => 'content', 'file_id' => $fileId]));
        [$status, $body] = $this->send('GET', $url, null, 20);
        if ($status < 200 || $status >= 300) throw new RuntimeException($this->apiError($body, $status));
        if ($body === '') throw new RuntimeException('The selected suite file is empty.');
        return $body;
    }

    public function uploadFile(string $spaceId, string $folderId, string $path, string $name): array
    {
        if (!is_file($path)) throw new RuntimeException('Report file is missing.');
        $fields = [
            'space_id' => $spaceId,
            'file' => new \CURLFile($path, 'text/markdown', $name),
        ];
        if ($folderId !== '') $fields['folder_id'] = $folderId;
        $url = $this->url('/files?action=upload');
        [$status, $body] = $this->send('POST', $url, $fields, 20);
        if ($status < 200 || $status >= 300) throw new RuntimeException($this->apiError($body, $status));
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || (($decoded['ok'] ?? true) === false)) {
            throw new RuntimeException('Ghost Files API returned an invalid upload response.');
        }
        return $decoded;
    }

    public function probe(): array
    {
        return $this->requestJson('GET', '/settings');
    }

    private function requestJson(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->url($path);
        $fields = null;
        $headers = ['Content-Type: application/json'];
        if ($payload !== null) $fields = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $body] = $this->send($method, $url, $fields, 20, $headers);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status < 200 || $status >= 300 || (($decoded['ok'] ?? true) === false)) {
            throw new RuntimeException($this->apiError($body, $status));
        }
        return $decoded;
    }

    private function send(string $method, string $url, mixed $payload, int $timeout, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json, text/plain, */*',
        ], $extraHeaders);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false) throw new RuntimeException('Ghost API transport failed: ' . $error);
        return [$status, (string) $response];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function apiError(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $decoded['message'] ?? '') : '';
        return 'Ghost API request failed (' . $status . ')' . ($message !== '' ? ': ' . substr($message, 0, 300) : '.');
    }
}
