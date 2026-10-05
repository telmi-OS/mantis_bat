<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function meetJson(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"success":false,"error":"internal_error"}';
    exit;
}

$services = null;
try {
    $services = require dirname(__DIR__, 3) . '/src/bootstrap.php';
} catch (Throwable $exception) {
    error_log('nextcloud-talk-connect: API bootstrap failed: ' . $exception->getMessage());
    meetJson(503, ['success' => false, 'error' => 'service_unavailable']);
}

function meetLog(array $services, string $result, string $nameKey, int $upstreamStatus = 0): void
{
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' meet.create result=' . $result . ' name_hash=' . substr($nameKey, 0, 12) . ' nextcloud_http=' . $upstreamStatus . "\n";
    @file_put_contents($services['installer']->logPath(), $line, FILE_APPEND | LOCK_EX);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    meetJson(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$config = $services['config'];
if (!$config->isInstalled()) meetJson(503, ['success' => false, 'error' => 'not_configured']);
$providedKey = isset($_SERVER['HTTP_X_AUTH']) && is_string($_SERVER['HTTP_X_AUTH']) ? $_SERVER['HTTP_X_AUTH'] : '';
if (!$services['security']->equals((string) $config->get('api.auth_key', ''), $providedKey)) {
    meetJson(401, ['success' => false, 'error' => 'unauthorized']);
}

$rawName = $_SERVER['HTTP_X_MEETING_NAME'] ?? '';
if (!is_string($rawName)) meetJson(400, ['success' => false, 'error' => 'invalid_meeting_name']);
if (preg_match('//u', $rawName) !== 1) meetJson(400, ['success' => false, 'error' => 'invalid_meeting_name']);
$name = preg_replace('/\s+/u', ' ', trim($rawName)) ?? '';
$name = preg_replace('/\p{Cc}+/u', '', $name) ?? '';
$name = trim($name);
if ($name !== '') $name = mb_substr($name, 0, (int) $config->get('meeting.name_max_length', 100), 'UTF-8');
if ($name === '') $name = (string) $config->get('meeting.default_name', 'Teleport AI Meeting');
if ($name === '' || preg_match('//u', $name) !== 1) meetJson(400, ['success' => false, 'error' => 'invalid_meeting_name']);

$nameKey = hash('sha256', mb_convert_case($name, MB_CASE_FOLD, 'UTF-8'));
$lockPath = $services['installer']->dedupeLockPath() . '/' . $nameKey . '.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) meetJson(503, ['success' => false, 'error' => 'service_unavailable']);
@chmod($lockPath, 0600);
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    meetJson(503, ['success' => false, 'error' => 'service_unavailable']);
}

$upstreamStatus = 0;
$reservationAt = 0;
try {
    $now = time();
    $services['storage']->purgeExpiredMeetings($now);
    $cached = $services['storage']->recentMeeting($nameKey, $now);
    if (is_array($cached)) {
        if (($cached['status'] ?? '') === 'pending') meetJson(503, ['success' => false, 'error' => 'meeting_creation_in_progress']);
        if (($cached['status'] ?? '') === 'failed') {
            $cachedError = in_array(($cached['error_code'] ?? ''), ['nextcloud_auth_failed', 'meeting_creation_failed'], true)
                ? (string) $cached['error_code']
                : 'meeting_creation_failed';
            meetJson(502, ['success' => false, 'error' => $cachedError]);
        }
        meetJson(200, [
            'success' => true, 'meeting_name' => (string) $cached['meeting_name'],
            'meeting_url' => (string) $cached['meeting_url'], 'token' => (string) $cached['token'],
        ]);
    }
    if (!$services['storage']->allowNewCreation($now)) {
        meetLog($services, 'rate_limited', $nameKey);
        meetJson(429, ['success' => false, 'error' => 'rate_limited']);
    }
    $reservationAt = time();
    $services['storage']->reserveMeeting($nameKey, $name, $reservationAt, $reservationAt + (int) $config->get('meeting.dedupe_seconds', 300));
    $talk = $services['talk'];
    $created = $talk->createPublicRoom($name);
    $upstreamStatus = (int) $created['http_status'];
    $token = (string) $created['token'];
    $meetingUrl = rtrim((string) $config->get('nextcloud.base_url'), '/') . '/call/' . rawurlencode($token);
    $services['storage']->saveMeeting($nameKey, $token, $meetingUrl, $reservationAt);
    meetLog($services, 'success', $nameKey, $upstreamStatus);
    meetJson(200, ['success' => true, 'meeting_name' => $name, 'meeting_url' => $meetingUrl, 'token' => $token]);
} catch (Throwable $exception) {
    $errorCode = $exception instanceof MantisBat\NextcloudTalkConnect\TalkAuthenticationException
        ? 'nextcloud_auth_failed'
        : 'meeting_creation_failed';
    if ($reservationAt > 0) {
        try { $services['storage']->failMeeting($nameKey, $reservationAt, $errorCode); } catch (Throwable) {}
    }
    meetLog($services, 'failed', $nameKey, $upstreamStatus);
    error_log('nextcloud-talk-connect: meeting creation failed: ' . $exception->getMessage());
    meetJson(502, ['success' => false, 'error' => $errorCode]);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
