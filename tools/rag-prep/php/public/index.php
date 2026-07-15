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

if (!$config->isInstalled()) {
    header('Location: install.php', true, 302);
    exit;
}

ragPrepRequireAccess($config, $security);

$csrfToken = $_SESSION['mantis_bat_rag_prep_dashboard_csrf'] ?? $security->randomToken(16);
$_SESSION['mantis_bat_rag_prep_dashboard_csrf'] = $csrfToken;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!$security->constantTimeEquals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Invalid dashboard session token. Reload and try again.');
        }

        $remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($storage->hitRateLimit('job_create_ip', $remoteIp, 20, 3600)) {
            throw new RuntimeException('Too many upload attempts. Wait and try again.');
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $guidance = trim((string) ($_POST['guidance'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Job title is required.');
        }

        if (!isset($_FILES['documents']) || !is_array($_FILES['documents']['name'] ?? null)) {
            throw new RuntimeException('Select at least one document.');
        }

        $fileNames = $_FILES['documents']['name'];
        $tmpNames = $_FILES['documents']['tmp_name'];
        $sizes = $_FILES['documents']['size'];
        $errors = $_FILES['documents']['error'];
        $count = count($fileNames);
        $maxFiles = (int) $config->get('limits.max_files_per_job', 5);
        if ($count < 1 || $count > $maxFiles) {
            throw new RuntimeException(sprintf('Each job must contain between 1 and %d files.', $maxFiles));
        }

        $maxFileSizeBytes = (int) $config->get('limits.max_file_size_mb', 15) * 1024 * 1024;
        $maxJobSizeBytes = (int) $config->get('limits.max_job_size_mb', 20) * 1024 * 1024;
        $allowedExtensions = ['txt', 'pdf', 'docx'];
        $jobUuid = $security->randomToken(12);
        $uploadDir = $installer->uploadsPath() . '/' . $jobUuid;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create upload directory.');
        }

        $jobId = $storage->createJob($jobUuid, $title, $guidance);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $totalBytes = 0;

        foreach ($fileNames as $index => $originalName) {
            $originalName = is_string($originalName) ? trim($originalName) : '';
            $tmpName = is_string($tmpNames[$index] ?? null) ? $tmpNames[$index] : '';
            $size = isset($sizes[$index]) ? (int) $sizes[$index] : 0;
            $error = isset($errors[$index]) ? (int) $errors[$index] : UPLOAD_ERR_NO_FILE;

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('One of the uploaded files failed during upload.');
            }
            if ($originalName === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Uploaded file data is invalid.');
            }
            if ($size <= 0 || $size > $maxFileSizeBytes) {
                throw new RuntimeException(sprintf('Each file must be between 1 byte and %d MB.', (int) $config->get('limits.max_file_size_mb', 15)));
            }

            $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new RuntimeException('Unsupported file type: ' . $originalName);
            }

            $totalBytes += $size;
            if ($totalBytes > $maxJobSizeBytes) {
                throw new RuntimeException(sprintf('Combined job upload size exceeds %d MB.', (int) $config->get('limits.max_job_size_mb', 20)));
            }

            $mimeType = (string) $finfo->file($tmpName);
            $storedName = sprintf('%02d-%s.%s', $index + 1, $security->randomToken(6), $extension);
            $storedPath = $uploadDir . '/' . $storedName;
            if (!move_uploaded_file($tmpName, $storedPath)) {
                throw new RuntimeException('Could not move uploaded file into storage.');
            }

            $storage->addJobFile($jobId, $originalName, $storedPath, $mimeType, $extension, $size);
        }

        $message = 'Job created. Cron will extract the documents and ask your Ghost to build the final telmi OS-ready txt artifact.';
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
    }
}

$jobs = $storage->listJobs();
$limits = [
    'max_file_size_mb' => (int) $config->get('limits.max_file_size_mb', 15),
    'max_job_size_mb' => (int) $config->get('limits.max_job_size_mb', 20),
    'max_files_per_job' => (int) $config->get('limits.max_files_per_job', 5),
    'max_source_characters' => (int) $config->get('limits.max_source_characters', 120000),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>telmi OS RAG Prep Tool | Mantis Bat</title>
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
                <h1><span class="gradient-text">telmi OS</span> RAG Prep</h1>
            </div>
        </div>
        <div class="hero-grid">
            <div class="copy">
                <p>Upload TXT, PDF, and DOCX documents. The tool extracts the text locally, sends one normalized source document to your Ghost, and saves one telmi OS-ready `.txt` artifact with semantically chunked memory blocks.</p>
                <p>Limits: <?= $limits['max_files_per_job'] ?> files per job, <?= $limits['max_file_size_mb'] ?> MB per file, <?= $limits['max_job_size_mb'] ?> MB per job, <?= number_format($limits['max_source_characters']) ?> extracted characters per Ghost pass.</p>
            </div>
            <div class="hero-shot">
                <img src="assets/telmi-os-desktop.png" alt="telmi OS desktop">
            </div>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <section class="card">
            <h2><span class="gradient-text">Status</span></h2>
            <pre><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></pre>
        </section>
    <?php endif; ?>

    <div class="card-grid">
        <section class="card">
            <h2><span class="gradient-text">Create Job</span></h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    Job Title
                    <input type="text" name="title" value="" maxlength="180" required>
                </label>
                <label>
                    Additional Ghost Guidance
                    <textarea name="guidance" rows="8" placeholder="Optional extra instructions for how the Ghost should prepare the final RAG chunk artifact."></textarea>
                </label>
                <label>
                    Documents
                    <input type="file" name="documents[]" accept=".txt,.pdf,.docx,text/plain,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple required>
                </label>
                <button type="submit">Create Processing Job</button>
            </form>
        </section>

        <section class="card">
            <h2><span class="gradient-text">Jobs</span></h2>
            <?php if ($jobs === []): ?>
                <p class="copy">No jobs yet. Create the first one above.</p>
            <?php else: ?>
                <div class="json-shell">
                    <?php foreach ($jobs as $job): ?>
                        <div style="padding:18px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                            <strong><?= htmlspecialchars((string) $job['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <span><?= htmlspecialchars((string) strtoupper((string) $job['status']), ENT_QUOTES, 'UTF-8') ?></span><br>
                            <span><?= (int) ($job['file_count'] ?? 0) ?> files</span><br>
                            <span><?= (int) ($job['input_char_count'] ?? 0) ?> input chars / <?= (int) ($job['output_char_count'] ?? 0) ?> output chars</span><br>
                            <?php if ((string) ($job['error_message'] ?? '') !== ''): ?>
                                <span><?= htmlspecialchars((string) $job['error_message'], ENT_QUOTES, 'UTF-8') ?></span><br>
                            <?php endif; ?>
                            <?php if ((string) ($job['status'] ?? '') === 'completed'): ?>
                                <a href="download.php?id=<?= (int) $job['id'] ?>">Download TXT Artifact</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
</body>
</html>
