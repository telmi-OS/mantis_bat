<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class GhostClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $token)
    {
    }

    public function probe(): array
    {
        return $this->request('GET', '/settings', []);
    }

    public function chat(string $message, string $submissionId = ''): array
    {
        return $this->request('POST', '/chat', [
            'message' => $message,
            'message_id' => $submissionId,
            'message_ts' => date(DATE_ATOM),
            'mode' => 'queued',
            'history' => true,
            'use_history' => true,
            'meta' => ['source' => 'mantis_bat_google_calendar', 'submission_id' => $submissionId],
            'options' => ['mode' => 'queued', 'use_history' => true],
        ]);
    }

    private function request(string $method, string $path, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_HEADER => true,
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($response === false) throw new RuntimeException('Ghost API request failed: ' . $error);
        $body = substr((string) $response, $headerSize);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $status >= 400 || (($decoded['ok'] ?? true) === false)) {
            throw new RuntimeException('Ghost API request failed (' . $status . '): ' . (string) ($decoded['error'] ?? 'invalid response'));
        }
        return $decoded;
    }
}
