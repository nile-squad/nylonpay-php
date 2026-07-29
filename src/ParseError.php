<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Parse serialized SDK errors into structured categories.
 */
final class ParseError
{
    /** @var list<string> */
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
        if (
            is_array($decoded)
            && isset($decoded['category'], $decoded['message'])
            && is_string($decoded['category'])
            && is_string($decoded['message'])
        ) {
            return new SdkError(
                $decoded['category'],
                $decoded['message'],
                isset($decoded['retryable']) ? (bool) $decoded['retryable'] : null,
            );
        }

        [$category, $message] = self::parseCategoryFromMessage($error);

        return new SdkError($category ?? 'internal', $message);
    }

    public static function createSdkException(SdkError $error): SdkException
    {
        return new SdkException($error->category, $error->message, $error->retryable);
    }

    public static function serialize(SdkError $error): string
    {
        return json_encode($error->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string|null, 1: string} */
    private static function parseCategoryFromMessage(string $message): array
    {
        if (preg_match('/^(.*?)\s*--\s*error-type:\s*([a-z_]+)\s*$/is', $message, $matches) === 1) {
            $category = $matches[2];
            if (in_array($category, self::KNOWN_CATEGORIES, true)) {
                return [$category, $matches[1]];
            }
        }

        return [null, $message];
    }
}
