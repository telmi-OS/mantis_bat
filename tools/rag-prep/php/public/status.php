<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];

$expectedKey = (string) $config->get('app.status_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;

if ($expectedKey !== '' && !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$status = $config->publicStatus();
$status['jobs'] = $storage->countJobs();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS RAG Prep Status | Mantis Bat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="page-shell">
    <section class="hero">
        <div class="brand-row">
            <img src="assets/mantis-mini.svg" alt="Mantis Bat">
            <div>
                <p class="eyebrow">Private Runtime Diagnostics</p>
                <h1><span class="gradient-text">telmi OS</span> RAG Prep Status</h1>
            </div>
        </div>
        <div class="hero-grid">
            <div class="copy">
                <p>This page exposes the live masked status of your self-hosted Mantis Bat RAG prep tool. Keep this URL private. It is intended for the runtime owner, not for public sharing.</p>
            </div>
            <div class="hero-shot">
                <img src="assets/telmi-os-desktop.png" alt="telmi OS desktop">
            </div>
        </div>
    </section>

    <section class="card">
        <h2><span class="gradient-text">Masked Runtime State</span></h2>
        <div class="json-shell">
            <pre><?= htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
        </div>
    </section>
</div>
</body>
</html>
