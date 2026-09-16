<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$moduleRoot = dirname(__DIR__);
$services = require $moduleRoot . '/src/bootstrap.php';

/**
 * @return bool
 */
function ragPrepGrantAccessIfValid(MantisBat\RuntimeConfig $config, MantisBat\Security $security): bool
{
    $sessionKey = 'mantis_bat_rag_prep_access';
    if (($_SESSION[$sessionKey] ?? false) === true) {
        return true;
    }

    $providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    $expectedKey = (string) $config->get('app.access_secret', '');
    if ($expectedKey !== '' && $security->constantTimeEquals($expectedKey, $providedKey)) {
        $_SESSION[$sessionKey] = true;
        return true;
    }

    return false;
}

function ragPrepRequireAccess(MantisBat\RuntimeConfig $config, MantisBat\Security $security): void
{
    if (!ragPrepGrantAccessIfValid($config, $security)) {
        http_response_code(404);
        echo 'Not found.';
        exit;
    }
}

function ragPrepIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return 0;
    }

    $suffix = strtolower(substr($value, -1));
    $number = (float) $value;
    $multiplier = match ($suffix) {
        'g' => 1024 * 1024 * 1024,
        'm' => 1024 * 1024,
        'k' => 1024,
        default => 1,
    };

    return (int) ($number * $multiplier);
}
