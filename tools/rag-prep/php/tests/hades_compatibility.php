<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);

foreach ([
    'Security',
    'RuntimeConfig',
    'Storage',
    'Logger',
    'Installer',
    'RetryableException',
    'GhostClient',
    'DocumentExtractor',
    'JobProcessor',
] as $classFile) {
    require_once $moduleRoot . '/src/' . $classFile . '.php';
}

use MantisBat\DocumentExtractor;
use MantisBat\GhostClient;
use MantisBat\Installer;
use MantisBat\JobProcessor;
use MantisBat\RuntimeConfig;
use MantisBat\Security;
use MantisBat\Storage;

function hadesExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hadesExpectContains(string $needle, string $haystack, string $message): void
{
    hadesExpect(str_contains($haystack, $needle), $message . "\nExpected: {$needle}\nActual: {$haystack}");
}

function hadesMakePdf(string $stream, bool $encrypted = false): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        4 => '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($id = 1; $id <= count($objects); $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R";
    if ($encrypted) {
        $pdf .= " /Encrypt 6 0 R";
    }
    $pdf .= " >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

    return $pdf;
}

function hadesCleanup(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            hadesCleanup($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

function hadesForceRetry(Storage $storage, int $jobId): void
{
    $pdo = new PDO('sqlite:' . $storage->getDatabasePath());
    $pdo->query('UPDATE jobs SET next_attempt_at = 0 WHERE id = ' . $jobId);
    $job = $storage->nextPendingJob(3, 60, 60);
    hadesExpect($job !== null, 'A retryable job should be pending after its backoff is cleared.');
}

function hadesAssertNoForbiddenCalls(string $sourceRoot): void
{
    $forbidden = [
        'ex' . 'ec',
        'shell_' . 'exec',
        'sys' . 'tem',
        'pass' . 'thru',
        'po' . 'pen',
        'proc_' . 'open',
        'proc_get_' . 'status',
        'proc_' . 'terminate',
        'pcntl_' . 'exec',
        'pcntl_' . 'fork',
        'd' . 'l',
    ];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $tokenCount = count($tokens);
        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $forbidden, true)) {
                continue;
            }

            $next = $index + 1;
            while ($next < $tokenCount && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if ($next >= $tokenCount || $tokens[$next] !== '(') {
                continue;
            }

            $previous = $index - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }
            if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            throw new RuntimeException(sprintf('Forbidden process call %s() found in %s.', $token[1], $file->getPathname()));
        }
    }
}

$temporaryRoot = sys_get_temp_dir() . '/mantis-bat-hades-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0775, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('Could not create compatibility test directory.');
}

try {
    $config = new RuntimeConfig($temporaryRoot . '/missing-config.php');
    $extractor = new DocumentExtractor($config);

    $txtPath = $temporaryRoot . '/sample.txt';
    file_put_contents($txtPath, "TXT extraction works.\n");
    hadesExpectContains('TXT extraction works.', $extractor->extract($txtPath, 'txt'), 'TXT extraction failed.');

    $docxPath = $temporaryRoot . '/sample.docx';
    $zip = new ZipArchive();
    hadesExpect($zip->open($docxPath, ZipArchive::CREATE) === true, 'Could not create DOCX fixture.');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>DOCX first paragraph.</w:t></w:r></w:p><w:p><w:r><w:t>DOCX second paragraph.</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $docxText = $extractor->extract($docxPath, 'docx');
    hadesExpectContains('DOCX first paragraph.', $docxText, 'DOCX first paragraph was not extracted.');
    hadesExpectContains('DOCX second paragraph.', $docxText, 'DOCX second paragraph was not extracted.');

    $pdfPath = $temporaryRoot . '/sample.pdf';
    file_put_contents($pdfPath, hadesMakePdf("BT /F1 12 Tf 72 720 Td (Pure PHP PDF extraction works.) Tj ET"));
    hadesExpectContains('Pure PHP PDF extraction works.', $extractor->extract($pdfPath, 'pdf'), 'Text-based PDF extraction failed.');

    $encryptedPdfPath = $temporaryRoot . '/encrypted.pdf';
    file_put_contents($encryptedPdfPath, hadesMakePdf("BT /F1 12 Tf 72 720 Td (Should not be read.) Tj ET", true));
    try {
        $extractor->extract($encryptedPdfPath, 'pdf');
        throw new RuntimeException('Encrypted PDF was accepted.');
    } catch (RuntimeException $exception) {
        hadesExpectContains('Encrypted PDFs are not supported.', $exception->getMessage(), 'Encrypted PDF error was not explicit.');
    }

    $scannedPdfPath = $temporaryRoot . '/scanned.pdf';
    file_put_contents($scannedPdfPath, hadesMakePdf('q Q'));
    try {
        $extractor->extract($scannedPdfPath, 'pdf');
        throw new RuntimeException('Image-only PDF was accepted without text.');
    } catch (RuntimeException $exception) {
        hadesExpectContains('require OCR', $exception->getMessage(), 'Scanned PDF error did not explain the OCR requirement.');
    }

    hadesAssertNoForbiddenCalls($moduleRoot . '/src');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require $moduleRoot . '/public/install.php';
    $installerHtml = (string) ob_get_clean();
    hadesExpectContains('<title>telmi OS RAG Prep Install', $installerHtml, 'Installer did not render normally.');
    $pdfBinaryName = 'pdf' . 'totext';
    hadesExpect(!str_contains($installerHtml, $pdfBinaryName), 'Installer output still mentions the legacy PDF binary.');

    $installerCheck = new Installer($temporaryRoot . '/installer-check', new Security());
    $requirements = $installerCheck->requirements();
    hadesExpect(!array_key_exists($pdfBinaryName, $requirements), 'Installer still reports a legacy PDF binary requirement.');
    hadesExpect(!array_key_exists('gd', $requirements), 'Installer incorrectly requires GD.');
    hadesExpect($installerCheck->allRequirementsPass(), 'Installer requirements did not pass under Hades-compatible extensions.');

    $retryRoot = $temporaryRoot . '/retry';
    $security = new Security();
    $installer = new Installer($retryRoot, $security);
    $installer->ensureRuntimeDirectories();
    $retryConfigPath = $retryRoot . '/config.php';
    file_put_contents($retryConfigPath, "<?php\nreturn " . var_export([
        'app' => ['timezone' => 'UTC'],
        'ghost' => ['api_base' => 'https://127.0.0.1:1', 'api_token' => 'test-token'],
        'limits' => ['max_attempts' => 3, 'retry_delays_seconds' => [60, 300], 'stale_job_seconds' => 60],
    ], true) . ";\n");
    $retryConfig = new RuntimeConfig($retryConfigPath);
    $storage = new Storage($installer->databasePath());
    $storage->migrate();
    $retryFile = $retryRoot . '/storage/uploads/retry.txt';
    file_put_contents($retryFile, 'Retry extraction source.');
    $jobId = $storage->createJob('retry-job', 'Retry job', '');
    $storage->addJobFile($jobId, 'retry.txt', $retryFile, 'text/plain', 'txt', filesize($retryFile));
    $processor = new JobProcessor($retryConfig, $storage, new GhostClient($retryConfig), new DocumentExtractor($retryConfig), $installer);

    putenv('NO_PROXY=127.0.0.1,localhost');
    $first = $processor->processNext();
    hadesExpect(($first['status'] ?? '') === 'retryable', 'First transient Ghost failure was not retryable.');
    $job = $storage->getJob($jobId);
    hadesExpect(($job['attempt_count'] ?? 0) === 1, 'First attempt was not persisted.');
    hadesExpect((int) ($job['next_attempt_at'] ?? 0) >= time() + 59, 'First retry did not persist a one-minute backoff.');

    hadesForceRetry($storage, $jobId);
    $second = $processor->processNext();
    hadesExpect(($second['status'] ?? '') === 'retryable', 'Second transient Ghost failure was not retryable.');
    $job = $storage->getJob($jobId);
    hadesExpect(($job['attempt_count'] ?? 0) === 2, 'Second attempt was not persisted.');
    hadesExpect((int) ($job['next_attempt_at'] ?? 0) >= time() + 299, 'Second retry did not persist a five-minute backoff.');

    hadesForceRetry($storage, $jobId);
    $third = $processor->processNext();
    hadesExpect(($third['status'] ?? '') === 'failed', 'Third transient Ghost failure did not become terminal.');
    $job = $storage->getJob($jobId);
    hadesExpect(($job['attempt_count'] ?? 0) === 3, 'Third attempt was not persisted.');
    hadesExpect(str_contains((string) ($job['last_error'] ?? ''), 'Ghost API request failed'), 'Last retry error was not persisted.');

    echo "Hades compatibility checks passed.\n";
} finally {
    hadesCleanup($temporaryRoot);
}
