<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Parse serialized SDK errors into structured reasons.
 *
 * @phpstan-import-type SdkErrorCategory from SdkError
 */
final class ParseError
{
    /** @var list<SdkErrorCategory> */
    private const KNOWN_CATEGORIES = [
        'auth',
        'validation',
        'limit',
        'rate_limit',
        'account',
        'provider',
        'duplicate',
        'not_found',
        'internal',
        'network',
        'timeout',
    ];

    public static function parse(string $error): SdkError
    {
        $decoded = json_decode($error, true);
        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            $hasReason = isset($decoded['reason']) && is_string($decoded['reason']);
            $hasCategory = isset($decoded['category']) && is_string($decoded['category']);
            if ($hasReason || $hasCategory) {
                return SdkError::from([
                    'reason' => $hasReason ? $decoded['reason'] : null,
                    'category' => $hasCategory ? $decoded['category'] : null,
                    'message' => $decoded['message'],
                    'retryable' => isset($decoded['retryable']) ? (bool) $decoded['retryable'] : null,
                    'code' => isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null,
                ]);
            }
        }

        [$category, $message, $code] = self::parseCategoryFromMessage($error);

        return SdkError::from([
            'category' => $category,
            'message' => $message,
            'code' => $code,
        ]);
    }

    public static function createSdkException(SdkError $error): SdkException
    {
        return new SdkException(
            $error->reason,
            $error->message,
            $error->retryable,
            $error->category,
            $error->code,
        );
    }

    public static function serialize(SdkError $error): string
    {
        return json_encode($error->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array{0: SdkErrorCategory|null, 1: string, 2: string|null} */
    private static function parseCategoryFromMessage(string $message): array
    {
        if (preg_match('/^(.*?)\s*--\s*error-type:\s*([a-z_]+)(?:\s*--\s*error-code:\s*([a-z0-9_]+))?\s*$/is', $message, $matches) === 1) {
            $category = $matches[2];
            if (in_array($category, self::KNOWN_CATEGORIES, true)) {
                return [$category, $matches[1], $matches[3] ?? null];
            }
        }

        return [null, $message, null];
    }
}
