<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — autoload + optional .env for integration suites.
 * Does not override variables already present in the process environment.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$candidates = [
    dirname(__DIR__) . '/.env',
    dirname(__DIR__) . '/../typescript/.env',
];

foreach ($candidates as $envPath) {
    if (!is_file($envPath)) {
        continue;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        break;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    break;
}
