<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

$config = $services['config'];
$security = $services['security'];
$installer = $services['installer'];
$storage = $services['storage'];
$key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if (!$config->isInstalled() || !$security->equals((string) $config->get('app.status_secret', ''), $key)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}
$csrf = ghostEvalCsrf($services);
$message = '';
$didReset = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        ghostEvalCheckCsrf($services);
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'clear_runs') {
            $storage->resetRuns();
            foreach (glob($installer->reportsPath() . '/*') ?: [] as $path) if (is_file($path)) @unlink($path);
            $message = 'Local run records and cached reports were removed. Files-space suites and uploaded reports were left unchanged.';
        } elseif ($action === 'factory_reset') {
            if ((string) ($_POST['confirmation'] ?? '') !== 'RESET') throw new RuntimeException('Type RESET to confirm factory reset.');
            $installer->reset();
            $didReset = true;
            $message = 'Local Ghost Eval installation removed. Files-space suites and reports were left unchanged.';
        } else {
            throw new RuntimeException('Unknown maintenance action.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ghost Eval Maintenance</title><link rel="stylesheet" href="assets/theme.css"></head>
<body><main class="page-shell"><section class="hero"><div class="brand-row"><img src="assets/mantis-mini.svg" alt="Mantis Bat"><div><p class="eyebrow">Private Runtime Maintenance</p><h1><span class="gradient-text">Ghost Eval</span></h1></div></div><p class="copy">Version <?= ghostEvalH((string) $config->get('app.version', '0.1.0')) ?> · Build <?= ghostEvalH($config->buildFingerprint()) ?></p></section>
<?php if ($message !== ''): ?><section class="card"><p><?= ghostEvalH($message) ?></p></section><?php endif; ?>
<?php if (!$didReset): ?><div class="dashboard-grid"><section class="card"><h2>Clear local history</h2><p class="copy">Removes local run records and cached Markdown reports. Files-space suites and uploaded reports remain.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="clear_runs"><button type="submit" class="button-secondary">Clear local runs</button></form></section><section class="card"><h2>Factory reset</h2><p class="copy">Removes local config, database, logs, and cached reports. It does not delete anything from the selected group Files space.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= ghostEvalH($csrf) ?>"><input type="hidden" name="action" value="factory_reset"><label>Type RESET to confirm<input name="confirmation" required></label><button type="submit">Factory reset</button></form></section></div><?php endif; ?></main></body></html>
