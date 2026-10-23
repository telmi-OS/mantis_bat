<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$security = $services['security'];
$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if (!$config->isInstalled() || !$security->equals((string) $config->get('app.status_secret', ''), $key)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}
$status = $config->status($security);
$status['runs'] = $services['storage']->counts();
$status['files_api'] = 'Authenticated with the configured Ghost JWT';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ghost Eval Status</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="page-shell"><section class="hero"><div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Private Runtime Diagnostics</p><h1><span class="gradient-text">Ghost Eval</span> Status</h1></div></div><p class="copy">Keep this URL private. Secrets are masked in this view.</p></section><section class="card"><h2>Runtime state</h2><pre><?= ghostEvalH(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre></section></main></body></html>
