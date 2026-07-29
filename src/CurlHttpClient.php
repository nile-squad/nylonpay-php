<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Streaming curl transport with mid-read response size enforcement (S17).
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{statusCode: int, body: string, reasonPhrase: string, headers: array<string, string>}
     */
    public function post(string $url, string $body, array $headers, int $timeoutMs): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize HTTP client');
        }

        $responseHeaders = [];
        $bodyBuffer = '';
        $oversized = false;
        $reasonPhrase = '';

        $headerFn = static function ($curl, string $headerLine) use (&$responseHeaders, &$reasonPhrase): int {
            $length = strlen($headerLine);

            if (preg_match('/^HTTP\/\S+\s+\d+\s*(.*)$/', trim($headerLine), $matches) === 1) {
                $reasonPhrase = trim($matches[1]);

                return $length;
            }

            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $length;
        };

        $writeFn = static function ($curl, string $chunk) use (&$bodyBuffer, &$oversized): int {
            $bodyBuffer .= $chunk;
            if (strlen($bodyBuffer) > Config::MAX_RESPONSE_BYTES) {
                $oversized = true;

                return 0;
            }

            return strlen($chunk);
        };

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_WRITEFUNCTION => $writeFn,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        ]);

        $executed = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($executed === false || $errno !== 0) {
            throw new \RuntimeException(
                $error !== '' ? $error : 'Could not reach the server',
            );
        }

        if ($oversized) {
            return [
                'statusCode' => $statusCode > 0 ? $statusCode : 200,
                'body' => '',
                'reasonPhrase' => 'Payload Too Large',
                'headers' => $responseHeaders + ['x-nylon-oversized' => '1'],
            ];
        }

        $declaredLength = $responseHeaders['content-length'] ?? null;
        if ($declaredLength !== null && (int) $declaredLength > Config::MAX_RESPONSE_BYTES) {
            return [
                'statusCode' => $statusCode > 0 ? $statusCode : 200,
                'body' => '',
                'reasonPhrase' => 'Payload Too Large',
                'headers' => $responseHeaders + ['x-nylon-oversized' => '1'],
            ];
        }

        return [
            'statusCode' => $statusCode,
            'body' => $bodyBuffer,
            'reasonPhrase' => $reasonPhrase !== '' ? $reasonPhrase : ('HTTP ' . $statusCode),
            'headers' => $responseHeaders,
        ];
    }
}
