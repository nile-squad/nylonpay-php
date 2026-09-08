<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Stable server fingerprint derived from OS metadata.
 *
 * Sent as `_fingerprint` in the request body and used as the first component
 * of `signatureInput`, so the value signed and the value sent must match.
 *
 * The server treats it as opaque: it reads `_fingerprint` out of the body and
 * feeds that value into its own HMAC, never computing one of its own. What
 * goes into the hash is therefore an implementation choice and can change
 * without breaking older clients, which sign with whatever they sent.
 *
 * PHP version and SAPI were removed on 2026-09-08: a runtime version is not
 * something every SDK can obtain the same way, and it made the value churn on
 * every upgrade for no benefit.
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
        ]);

        self::$cached = hash('sha256', $components);

        return self::$cached;
    }
}
