<?php

declare(strict_types=1);

$publicDir = __DIR__;
$candidates = array_filter([
    getenv('MANTIS_BAT_MODULE_ROOT') ?: null,
    getenv('TELMI_CONNECTOR_ROOT') ?: null,
    dirname($publicDir),
    dirname($publicDir) . '/connectors/telegram/php',
    dirname($publicDir, 2) . '/connectors/telegram/php',
    dirname($publicDir, 3) . '/connectors/telegram/php',
]);

foreach ($candidates as $candidate) {
    $candidate = rtrim((string) $candidate, '/');
    if (
        is_dir($candidate . '/src') &&
        is_file($candidate . '/src/bootstrap.php') &&
        is_file($candidate . '/src/Config.php') &&
        is_file($candidate . '/src/Storage.php') &&
        is_file($candidate . '/src/Installer.php') &&
        is_dir($candidate . '/templates')
    ) {
        return $candidate;
    }
}

http_response_code(500);
error_log('[Mantis Bat] Could not resolve connector module root from public entrypoint: ' . $publicDir);
exit('Connector bootstrap error.');
