<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * HMAC signing and JCS-compatible canonical payload serialization.
 */
final class Signature
{
    /**
     * Compare two keys by UTF-16 code unit, the RFC 8785 (JCS) ordering.
     *
     * WHY big-endian: comparing UTF-16BE bytes is equivalent to comparing
     * UTF-16 code units numerically, which is what JCS requires and what
     * JavaScript's `<` operator does. UTF-16LE is NOT equivalent, it compares
     * the low byte first, so "Ā" (U+0100 -> 00 01) sorts before "Z"
     * (U+005A -> 5A 00) while code-unit order puts "Z" first. Any sibling key
     * whose low byte is below 0x20 (Cyrillic, CJK, Latin Extended, emoji)
     * diverges under LE, and merchant metadata keys are arbitrary strings, so
     * the backend then rejects a correctly-formed request. Pinned by vector V7
     * in SignatureConformanceTest.
     */
    public static function compareKeys(string $first, string $second): int
    {
        $firstBytes = mb_convert_encoding($first, 'UTF-16BE', 'UTF-8');
        $secondBytes = mb_convert_encoding($second, 'UTF-16BE', 'UTF-8');

        return $firstBytes <=> $secondBytes;
    }

    public static function sortValue(mixed $value): mixed
    {
        // An `stdClass` is how a caller expresses an empty JSON object, since
        // PHP's `[]` is both an empty list and an empty map and json_encode
        // renders it as `[]`. Sort it and hand back an object so it still
        // serializes as `{}`.
        if (is_object($value)) {
            return (object) self::sortAssociative((array) $value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortValue(...), $value);
        }

        return self::sortAssociative($value);
    }

    /**
     * Sort one map's keys by UTF-16 code unit and recurse into its values.
     *
     * Keys are cast to string before comparison: PHP silently converts a
     * numeric string key such as `"0"` to an int, which would otherwise reach
     * `compareKeys(string, string)` as an int.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortAssociative(array $value): array
    {
        $keys = array_keys($value);
        usort($keys, static fn (mixed $first, mixed $second): int => self::compareKeys((string) $first, (string) $second));

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = self::sortValue($value[$key]);
        }

        return $sorted;
    }

    public static function createCanonicalPayload(mixed $payload): string
    {
        $sorted = self::sortValue($payload);

        return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{fingerprint: string, nonce: string, timestamp: string, payload: mixed, secret: string} $input
     */
    public static function createSignature(array $input): string
    {
        $signaturePayload = self::createSignaturePayload([
            'fingerprint' => $input['fingerprint'],
            'nonce' => $input['nonce'],
            'timestamp' => $input['timestamp'],
            'payload' => $input['payload'],
        ]);

        return hash_hmac('sha256', $signaturePayload, $input['secret']);
    }

    /**
     * @param array{fingerprint: string, nonce: string, timestamp: string, payload: mixed} $input
     */
    public static function createSignaturePayload(array $input): string
    {
        return sprintf(
            '%s.%s.%s.%s',
            $input['fingerprint'],
            $input['nonce'],
            $input['timestamp'],
            self::createCanonicalPayload($input['payload']),
        );
    }

    public static function createTimestamp(): string
    {
        return (string) (int) floor(microtime(true) * 1000);
    }
}
