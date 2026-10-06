<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Phone normalization and validation for provider-ready numbers.
 */
final class Phone
{
    /** @var array<string, string> */
    private const DIAL_BY_CURRENCY = [
        'CDF' => '243',
        'KES' => '254',
        'RWF' => '250',
        'TZS' => '255',
        'UGX' => '256',
        'XAF' => '237',
        'ZMW' => '260',
    ];

    public static function normalize(string $phone, string $currency = 'UGX'): string
    {
        $normalized = preg_replace('/\s+/', '', $phone) ?? $phone;
        $normalized = preg_replace('/^\+/', '', $normalized) ?? $normalized;

        $dial = self::DIAL_BY_CURRENCY[strtoupper($currency)] ?? '256';
        if (str_starts_with($normalized, '0') && strlen($normalized) === 10) {
            $normalized = $dial . substr($normalized, 1);
        } elseif (preg_match('/^\d{9}$/', $normalized) === 1) {
            // A local number typed without its 0; every market uses 9 digits.
            $normalized = $dial . $normalized;
        }

        return $normalized;
    }

    public static function isValidFormat(string $normalizedPhone): bool
    {
        return preg_match('/^\d{9,15}$/', $normalizedPhone) === 1;
    }
}
