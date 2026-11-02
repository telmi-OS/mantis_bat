<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/src/bootstrap.php';

function filesSyncS3H(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
