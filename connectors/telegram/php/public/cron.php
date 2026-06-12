<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var MantisBat\Config $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\InboxPoller $poller */
$poller = $services['inbox_poller'];
/** @var MantisBat\Logger $logger */
$logger = $services['logger'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];

$lockPath = dirname(__DIR__) . '/storage/cron.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    http_response_code(500);
    echo 'Could not open cron lock file.';
    exit;
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo 'Cron is already running.';
    exit;
}

try {
    if (PHP_SAPI !== 'cli') {
        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($storage->hitRateLimit('cron_http', $remoteIp, 30, 60)) {
            http_response_code(429);
            echo 'Too many requests.';
            exit;
        }

        $providedKey = $_GET['key'] ?? null;
        $expectedKey = (string) $config->get('app.cron_secret', '');
        if (!$security->constantTimeEquals($expectedKey, is_string($providedKey) ? $providedKey : null)) {
            http_response_code(403);
            echo 'Invalid cron key.';
            exit;
        }
    }

    $result = $poller->run();

    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $logger->exception($exception);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cron failed.'], JSON_UNESCAPED_SLASHES);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
