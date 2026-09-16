<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Phone normalization and validation for provider-ready numbers.
 */
final class Phone
{
    public static function normalize(string $phone, string $currency = 'UGX'): string
    {
        $normalized = preg_replace('/\s+/', '', $phone) ?? $phone;
        $normalized = preg_replace('/^\+/', '', $normalized) ?? $normalized;

        if (str_starts_with($normalized, '0') && strlen($normalized) === 10) {
            $dial = match (strtoupper($currency)) {
                'KES' => '254',
                'TZS' => '255',
                'RWF' => '250',
                'CDF' => '243',
                default => '256',
            };
            $normalized = $dial . substr($normalized, 1);
        }

        return $normalized;
    }

    public static function isValidFormat(string $normalizedPhone): bool
    {
        return preg_match('/^\d{9,15}$/', $normalizedPhone) === 1;
    }
}
