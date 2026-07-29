<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Support;

use NileSquad\NylonPay\HttpClient;

/**
 * Injectable HTTP client for unit and security tests.
 */
final class MockHttpClient implements HttpClient
{
    /** @var callable|null */
    private $handler;

    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, reasonPhrase: string, headers: array<string, string>}
     */
    public function post(string $url, string $body, array $headers, int $timeoutMs): array
    {
        if ($this->handler === null) {
            throw new \RuntimeException('MockHttpClient handler not configured');
        }

        /** @var array{statusCode: int, body: string, reasonPhrase?: string, headers?: array<string, string>} $response */
        $response = ($this->handler)($url, $body, $headers, $timeoutMs);

        return [
            'statusCode' => $response['statusCode'],
            'body' => $response['body'],
            'reasonPhrase' => $response['reasonPhrase'] ?? 'OK',
            'headers' => $response['headers'] ?? [],
        ];
    }
}
