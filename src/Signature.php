<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * HMAC signing and JCS-compatible canonical payload serialization.
 */
final class Signature
{
    public static function compareKeys(string $first, string $second): int
    {
        $firstBytes = mb_convert_encoding($first, 'UTF-16LE', 'UTF-8');
        $secondBytes = mb_convert_encoding($second, 'UTF-16LE', 'UTF-8');

        return $firstBytes <=> $secondBytes;
    }

    public static function sortValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortValue(...), $value);
        }

        $keys = array_keys($value);
        usort($keys, self::compareKeys(...));

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
