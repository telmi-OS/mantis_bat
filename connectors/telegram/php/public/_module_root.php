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

$diagnostics = [];
foreach ($candidates as $candidate) {
    $candidate = rtrim((string) $candidate, '/');
    $checks = [
        'src_dir' => is_dir($candidate . '/src'),
        'bootstrap' => is_file($candidate . '/src/bootstrap.php'),
        'config_class' => is_file($candidate . '/src/Config.php'),
        'storage_class' => is_file($candidate . '/src/Storage.php'),
        'installer_class' => is_file($candidate . '/src/Installer.php'),
        'templates_dir' => is_dir($candidate . '/templates'),
    ];
    $diagnostics[] = [
        'candidate' => $candidate,
        'checks' => $checks,
    ];

    if (
        $checks['src_dir'] &&
        $checks['bootstrap'] &&
        $checks['config_class'] &&
        $checks['storage_class'] &&
        $checks['installer_class'] &&
        $checks['templates_dir']
    ) {
        return $candidate;
    }
}

http_response_code(500);
error_log('[Mantis Bat] Could not resolve connector module root from public entrypoint: ' . $publicDir);
header('Content-Type: text/plain; charset=utf-8');
echo "Connector bootstrap error\n\n";
echo "Public dir:\n{$publicDir}\n\n";
echo "Environment hints:\n";
echo 'MANTIS_BAT_MODULE_ROOT=' . (getenv('MANTIS_BAT_MODULE_ROOT') ?: '[not set]') . "\n";
echo 'TELMI_CONNECTOR_ROOT=' . (getenv('TELMI_CONNECTOR_ROOT') ?: '[not set]') . "\n\n";
echo "Checked candidates:\n";

foreach ($diagnostics as $item) {
    echo "\n- " . $item['candidate'] . "\n";
    foreach ($item['checks'] as $name => $result) {
        echo '  ' . $name . ': ' . ($result ? 'yes' : 'no') . "\n";
    }
}

exit;
