<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Smalot\\PdfParser\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/Smalot/PdfParser/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
