<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Stable server fingerprint derived from runtime metadata.
 */
final class Fingerprint
{
    private static ?string $cached = null;

    public static function generate(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $components = implode('|', [
            'type:' . PHP_OS_FAMILY,
            'platform:' . php_uname('s') . ' ' . php_uname('r'),
            'arch:' . php_uname('m'),
            'release:' . php_uname('r'),
            'hostname:' . gethostname(),
            'php:' . PHP_VERSION,
            'sapi:' . PHP_SAPI,
        ]);

        self::$cached = hash('sha256', $components);

        return self::$cached;
    }
}
