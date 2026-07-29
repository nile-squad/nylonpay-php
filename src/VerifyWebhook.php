<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Webhook signature verification with replay protection.
 */
final class VerifyWebhook
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** Explicit opt-out of freshness checks — `0` means strict, not disabled. */
    public const DISABLE_FRESHNESS_CHECK = -1;

    /**
     * @param array{
     *   payload: string|resource,
     *   signature: string,
     *   secret: string,
     *   toleranceSeconds?: int|null
     * } $input
     */
    public static function verify(array $input): bool
    {
        try {
            $payloadBytes = self::toPayloadBytes($input['payload']);
            $expectedSignature = hash_hmac('sha256', $payloadBytes, $input['secret']);
            $signature = $input['signature'];

            if ($signature !== strtolower($signature)) {
                return false;
            }

            if (!preg_match('/^[0-9a-f]{64}$/', $signature)) {
                return false;
            }

            if (strlen($signature) !== strlen($expectedSignature)) {
                return false;
            }

            if (!hash_equals($expectedSignature, $signature)) {
                return false;
            }

            $tolerance = $input['toleranceSeconds'] ?? self::DEFAULT_TOLERANCE_SECONDS;
            if ($tolerance === self::DISABLE_FRESHNESS_CHECK) {
                return true;
            }

            if ($tolerance < 0) {
                return false;
            }

            $timestampMs = self::extractSignedTimestampMs($payloadBytes);
            if ($timestampMs === null) {
                return false;
            }

            $ageMs = abs((int) floor(microtime(true) * 1000) - $timestampMs);

            return $ageMs <= $tolerance * 1000;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function toPayloadBytes(mixed $payload): string
    {
        if (is_resource($payload)) {
            $contents = stream_get_contents($payload);

            return $contents === false ? '' : $contents;
        }

        if (!is_string($payload)) {
            return '';
        }

        return $payload;
    }

    private static function extractSignedTimestampMs(string $payloadBytes): ?int
    {
        $payloadString = $payloadBytes;
        $decoded = json_decode($payloadString, true);
        if (!is_array($decoded) || !array_key_exists('timestamp', $decoded)) {
            return null;
        }

        $raw = $decoded['timestamp'];

        if (is_int($raw) || is_float($raw)) {
            return $raw < 1e12 ? (int) ($raw * 1000) : (int) $raw;
        }

        if (!is_string($raw)) {
            return null;
        }

        if (is_numeric($raw)) {
            $numeric = (float) $raw;

            return $numeric < 1e12 ? (int) ($numeric * 1000) : (int) $numeric;
        }

        $normalized = str_ends_with($raw, 'Z') || str_ends_with($raw, 'z')
            ? substr($raw, 0, -1) . '+00:00'
            : $raw;

        try {
            $date = new \DateTimeImmutable($normalized);

            return (int) ($date->getTimestamp() * 1000);
        } catch (\Exception) {
            return null;
        }
    }
}
