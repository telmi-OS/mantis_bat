<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];
/** @var MantisBat\Installer $installer */
$installer = $services['installer'];

$expectedKey = (string) $config->get('app.status_secret', '');
$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : null;
$csrfToken = $_SESSION['mantis_bat_rag_prep_maintenance_csrf'] ?? $security->randomToken(16);
$_SESSION['mantis_bat_rag_prep_maintenance_csrf'] = $csrfToken;

if (!$config->isInstalled() || $expectedKey === '' || !$security->constantTimeEquals($expectedKey, $providedKey)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$message = '';
$didFactoryReset = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $postedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if ($action === '') {
            throw new RuntimeException('Missing maintenance action.');
        }
        if (!$security->constantTimeEquals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid maintenance session token. Reload the page and try again.');
        }

        if ($action === 'reset_jobs') {
            $storage->resetJobs();
            ragPrepDeleteDirectory($installer->jobsPath());
            ragPrepDeleteDirectory($installer->uploadsPath());
            $installer->ensureRuntimeDirectories();
            $message = 'All jobs and artifacts were removed. Ghost config, access URLs, and install state were kept.';
        } elseif ($action === 'factory_reset') {
            $confirmation = isset($_POST['confirmation']) && is_string($_POST['confirmation']) ? trim($_POST['confirmation']) : '';
            if ($confirmation !== 'RESET') {
                throw new RuntimeException('Factory reset requires typing RESET exactly.');
            }

            $installer->resetRuntime();
            $didFactoryReset = true;
            $message = 'Factory reset complete. Runtime config, database, uploads, jobs, logs, and install lock were removed. Open install.php to start again.';
        } else {
            throw new RuntimeException('Unknown maintenance action.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }
}

function ragPrepDeleteDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . '/' . $item;
        if (is_dir($child)) {
            ragPrepDeleteDirectory($child);
            continue;
        }
        @unlink($child);
    }

    @rmdir($path);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS RAG Prep Maintenance | Mantis Bat</title>
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
                <p class="eyebrow">Teleport AI Official Tool Repo</p>
                <h1><span class="gradient-text">RAG Prep</span> Maintenance</h1>
            </div>
        </div>
        <p class="copy">Use this protected page to manage the live RAG prep tool runtime without reinstalling unless you actually want a full reset.</p>
        <p class="copy"><strong>Version:</strong> <?= htmlspecialchars((string) $config->get('app.version', '0.1.0'), ENT_QUOTES, 'UTF-8') ?> <strong>Build:</strong> <?= htmlspecialchars($config->buildFingerprint(), ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <div class="card-grid">
        <?php if ($message !== ''): ?>
            <section class="card">
                <h2><span class="gradient-text">Status</span></h2>
                <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
            </section>
        <?php endif; ?>

        <?php if (!$didFactoryReset): ?>
            <section class="card">
                <h2><span class="gradient-text">Job Reset</span></h2>
                <p class="copy">This removes every current job, uploaded document, extracted source text, and final txt artifact. Ghost config and access URLs stay intact.</p>
                <form method="post">
                    <input type="hidden" name="action" value="reset_jobs">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Reset All Jobs</button>
                </form>
            </section>

            <section class="card">
                <h2><span class="gradient-text">Factory Reset</span></h2>
                <p class="copy">This removes the live config, database, uploads, jobs, logs, and install lock. Use it only if you want to start from zero.</p>
                <form method="post">
                    <input type="hidden" name="action" value="factory_reset">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label>
                        Type RESET to confirm
                        <input type="text" name="confirmation" value="" required>
                    </label>
                    <button type="submit">Factory Reset Tool</button>
                </form>
            </section>
        <?php else: ?>
            <section class="card">
                <h2><span class="gradient-text">Next Step</span></h2>
                <p class="copy">The tool runtime is gone now. Open <code>install.php</code> again to create a fresh install.</p>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
