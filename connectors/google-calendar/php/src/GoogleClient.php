<?php

declare(strict_types=1);

namespace MantisBat\GoogleCalendar;

use RuntimeException;

final class GoogleClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct(private readonly string $clientId, private readonly string $clientSecret, private readonly string $redirectUri)
    {
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.calendarlist.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function listCalendars(string $accessToken): array
    {
        $response = $this->request('GET', '/users/me/calendarList', $accessToken, ['maxResults' => 250]);
        return is_array($response['items'] ?? null) ? $response['items'] : [];
    }

    public function listEvents(string $accessToken, string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $events = [];
        $pageToken = null;
        do {
            $query = [
                'timeMin' => $from->format('c'),
                'timeMax' => $to->format('c'),
                'singleEvents' => 'true',
                'showDeleted' => 'true',
                'maxResults' => 2500,
                'orderBy' => 'startTime',
            ];
            if ($pageToken) $query['pageToken'] = $pageToken;
            $response = $this->request('GET', '/calendars/' . rawurlencode($calendarId) . '/events', $accessToken, $query);
            foreach (($response['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $normalized = $this->normalizeEvent($item, $calendarId, $from, $to);
                    if ($normalized !== null) $events[] = $normalized;
                }
            }
            $pageToken = isset($response['nextPageToken']) ? (string) $response['nextPageToken'] : null;
        } while ($pageToken !== null && $pageToken !== '');

        return $events;
    }

    private function normalizeEvent(array $item, string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') return null;
        $status = (string) ($item['status'] ?? 'confirmed');
        $startInfo = is_array($item['start'] ?? null) ? $item['start'] : [];
        $endInfo = is_array($item['end'] ?? null) ? $item['end'] : [];
        $allDay = isset($startInfo['date']);
        $start = (string) ($allDay ? ($startInfo['date'] ?? '') : ($startInfo['dateTime'] ?? ''));
        $end = (string) ($allDay ? ($endInfo['date'] ?? '') : ($endInfo['dateTime'] ?? ''));
        if (($start === '' || $end === '') && $status === 'cancelled') {
            $original = is_array($item['originalStartTime'] ?? null) ? $item['originalStartTime'] : [];
            $allDay = isset($original['date']);
            $start = (string) ($allDay ? ($original['date'] ?? '') : ($original['dateTime'] ?? ''));
            $end = $start;
        }
        if ($start === '' || $end === '') return null;

        if ($allDay) {
            $startDate = new \DateTimeImmutable($start . 'T00:00:00+00:00');
            $endDate = new \DateTimeImmutable($end . 'T00:00:00+00:00');
            $fromDate = new \DateTimeImmutable($from->format('Y-m-d') . 'T00:00:00+00:00');
            $toDate = new \DateTimeImmutable($to->format('Y-m-d') . 'T00:00:00+00:00');
            if ($status !== 'cancelled' && ($endDate <= $fromDate || $startDate >= $toDate)) return null;
        } else {
            try {
                $startDate = new \DateTimeImmutable($start);
                $endDate = new \DateTimeImmutable($end === $start ? $start : $end);
            } catch (\Throwable) {
                return null;
            }
            if ($status !== 'cancelled' && ($endDate <= $from || $startDate >= $to)) return null;
        }

        $identity = $calendarId . ':' . $id;
        return [
            'identity' => $identity,
            'calendar_id' => $calendarId,
            'event_id' => $id,
            'title' => (string) ($item['summary'] ?? ''),
            'start' => $start,
            'end' => $end,
            'all_day' => $allDay,
            'timezone' => (string) ($startInfo['timeZone'] ?? ''),
            'status' => $status === 'cancelled' ? 'cancelled' : 'confirmed',
            'is_recurring' => isset($item['recurringEventId']) || isset($item['recurrence']),
            'recurring_event_id' => (string) ($item['recurringEventId'] ?? ''),
        ];
    }

    private function tokenRequest(array $params): array
    {
        $params['client_id'] = $this->clientId;
        $params['client_secret'] = $this->clientSecret;
        $result = $this->rawRequest('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($params));
        $body = json_decode($result['body'], true);
        if (!is_array($body) || $result['status'] >= 400 || isset($body['error'])) {
            throw new RuntimeException('Google OAuth request failed: ' . (string) ($body['error_description'] ?? $body['error'] ?? 'unknown error'));
        }
        return $body;
    }

    private function request(string $method, string $path, string $accessToken, array $query = []): array
    {
        $url = self::API_BASE . $path;
        if ($query !== []) $url .= '?' . http_build_query($query);
        $result = $this->rawRequest($method, $url, ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'], null);
        $body = json_decode($result['body'], true);
        if (!is_array($body) || $result['status'] >= 400) {
            throw new RuntimeException('Google Calendar request failed (' . $result['status'] . '): ' . (string) ($body['error']['message'] ?? 'unknown error'));
        }
        return $body;
    }

    private function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false) throw new RuntimeException('Google request failed: ' . $error);
        return ['status' => $status, 'body' => (string) $response];
    }
}
