<?php

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';
$config = $services['config'];
if (!$config->installed()) { http_response_code(404); echo 'Not found.'; exit; }
$key = is_string($_GET['key'] ?? null) ? (string) $_GET['key'] : '';
if (!hash_equals((string) $config->get('app.cron_secret', ''), $key)) { http_response_code(404); echo 'Not found.'; exit; }
$requirements = $services['installer']->requirements();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => !in_array(false, $requirements, true), 'installed' => $config->installed(), 'requirements' => $requirements], JSON_UNESCAPED_SLASHES);
