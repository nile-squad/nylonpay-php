<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * HMAC verification for signed backend responses.
 */
final class VerifyResponse
{
    public static function verify(mixed $data, string $signature, string $secret): bool
    {
        if ($signature !== strtolower($signature)) {
            return false;
        }

        if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', Signature::createCanonicalPayload($data), $secret);

        if (strlen($signature) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $signature);
    }
}
