<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * HTTP client contract for transport injection in tests.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, reasonPhrase: string, headers: array<string, string>}
     */
    public function post(string $url, string $body, array $headers, int $timeoutMs): array;
}
