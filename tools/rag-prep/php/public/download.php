<?php

declare(strict_types=1);

require __DIR__ . '/_runtime.php';

/** @var MantisBat\RuntimeConfig $config */
$config = $services['config'];
/** @var MantisBat\Security $security */
$security = $services['security'];
/** @var MantisBat\Storage $storage */
$storage = $services['storage'];

if (!$config->isInstalled()) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

ragPrepRequireAccess($config, $security);

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$job = $jobId > 0 ? $storage->getJob($jobId) : null;
if ($job === null || (string) ($job['status'] ?? '') !== 'completed') {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$path = (string) ($job['output_text_path'] ?? '');
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($job['title'] ?? 'rag-prep')) ?: 'rag-prep';
$filename .= '.txt';

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $filename . '"');
readfile($path);
