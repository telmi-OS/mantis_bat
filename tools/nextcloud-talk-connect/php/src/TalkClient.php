<?php

declare(strict_types=1);

namespace MantisBat\NextcloudTalkConnect;

use RuntimeException;

final class TalkClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $username, private readonly string $appPassword, private readonly int $timeout = 10) {}

    public function probe(): void
    {
        $url = $this->baseUrl . '/ocs/v2.php/cloud/capabilities?format=json';
        [$status, $body] = $this->request($url, 'GET');
        $decoded = json_decode($body, true);
        if ($status === 401 || $status === 403) throw new TalkAuthenticationException('Nextcloud rejected the username or app password.');
        if ($status < 200 || $status >= 300 || !is_array($decoded) || strtolower((string) ($decoded['ocs']['meta']['status'] ?? '')) !== 'ok') {
            throw new RuntimeException('Could not validate the Nextcloud connection. Check the base URL, account, app password, and OCS access.');
        }
    }

    public function createPublicRoom(string $name): array
    {
        $url = $this->baseUrl . '/ocs/v2.php/apps/spreed/api/v4/room?format=json';
        [$status, $body] = $this->request($url, 'POST', ['roomType' => '3', 'roomName' => $name]);
        if ($status === 401 || $status === 403) throw new TalkAuthenticationException('Nextcloud rejected the configured account credentials.');
        if ($status < 200 || $status >= 300) throw new RuntimeException('Nextcloud Talk returned HTTP ' . $status . ' while creating a room.');
        $decoded = json_decode($body, true);
        $ocs = is_array($decoded) ? ($decoded['ocs'] ?? null) : null;
        if (!is_array($ocs) || strtolower((string) ($ocs['meta']['status'] ?? '')) !== 'ok') {
            $code = is_array($ocs) ? (string) ($ocs['meta']['statuscode'] ?? '') : '';
            if (in_array($code, ['401', '403', '997'], true)) throw new TalkAuthenticationException('Nextcloud Talk rejected the configured account credentials.');
            throw new RuntimeException('Nextcloud Talk rejected the room request' . ($code !== '' ? ' (OCS ' . $code . ')' : '') . '.');
        }
        $token = is_array($ocs['data'] ?? null) ? trim((string) ($ocs['data']['token'] ?? '')) : '';
        if ($token === '') throw new RuntimeException('Nextcloud Talk did not return a room token.');
        return ['token' => $token, 'http_status' => $status];
    }

    private function request(string $url, string $method, ?array $fields = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Could not initialize the Nextcloud request.');
        $headers = ['OCS-APIRequest: true', 'Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(5, min(30, $this->timeout)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->appPassword,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($fields !== null) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC1738);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) throw new RuntimeException('Nextcloud connection failed' . ($error !== '' ? ': ' . $error : '.') );
        return [$status, (string) $body];
    }
}
