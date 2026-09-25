<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$security = $services['security'];
$storage = $services['storage'];
$installer = $services['installer'];
$logger = $installer->logPath();

if (!$config->isInstalled()) {
    http_response_code(503);
    echo 'Not installed.';
    exit;
}

$lock = fopen($installer->storagePath() . '/cron.lock', 'c+');
if ($lock === false) {
    http_response_code(500);
    echo 'Could not open worker lock.';
    exit;
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo 'Worker is already running.';
    exit;
}

try {
    if (PHP_SAPI !== 'cli') {
        $provided = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
        if (!$security->equals((string) $config->get('app.cron_secret', ''), $provided)) {
            http_response_code(403);
            echo 'Invalid cron key.';
            exit;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($storage->hitRateLimit('cron_http', $ip, 1, 50)) {
            http_response_code(429);
            echo 'Wait before the next worker tick.';
            exit;
        }
    }
    $result = $services['runner']->processNext();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    $safe = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $exception->getMessage()) ?? 'Worker failed.';
    error_log('[Mantis Bat Ghost Eval] ' . substr($safe, 0, 500));
    @file_put_contents($logger, '[' . date(DATE_ATOM) . '] Worker failure: ' . substr($safe, 0, 500) . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Worker failed. Check the private status page.'], JSON_UNESCAPED_SLASHES);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
