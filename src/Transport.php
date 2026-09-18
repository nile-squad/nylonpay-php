<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Signed HTTP transport for Nile envelope requests.
 */
final class Transport
{
    private static string $cachedFingerprint;

    private readonly Reachability $reachability;

    /**
     * Wire features this SDK understands, sent on every request.
     *
     * `error-code` says `parseError` can read the optional
     * ` -- error-code: <code>` tail. Every release before this one parsed the
     * category with a regex anchored at the end of the message, so a code
     * appended after it left them with no match — category silently downgraded
     * to `internal`, and the raw suffixes shown to the user as part of the
     * message. The backend only appends a code for clients listed here.
     *
     * @var list<string>
     */
    private const CLIENT_FEATURES = ['error-code'];

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

    /** @var array<int, string> */
    private const STATUS_CATEGORY = [
        0 => 'network',
        408 => 'timeout',
        429 => 'rate_limit',
    ];

    /**
     * @param array{
     *   apiKey: string,
     *   apiSecret: string,
     *   baseUrl?: string,
     *   timeoutMs?: int,
     *   maxRetries?: int,
     *   httpClient?: HttpClient|null,
     *   onError?: callable|null
     * } $config
     *
     * The optional onError callback receives each final structured operation error.
     */
    public function __construct(private readonly array $config)
    {
        self::$cachedFingerprint ??= Fingerprint::generate();
        $baseUrl = $config['baseUrl'] ?? Config::DEFAULT_BASE_URL;
        $httpClient = $config['httpClient'] ?? null;
        $probe = static function () use ($baseUrl, $httpClient): ?string {
            $client = $httpClient ?? new CurlHttpClient();
            try {
                $response = $client->post(
                    $baseUrl,
                    '{}',
                    ['content-type' => 'application/json'],
                    Config::REACHABILITY_PROBE_TIMEOUT_MS,
                );

                return Reachability::classifyHttpStatus($response['statusCode']);
            } catch (\Throwable $error) {
                return Reachability::classifyError($error->getMessage(), (int) $error->getCode());
            }
        };
        $this->reachability = new Reachability(
            null,
            $probe,
        );
    }

    /**
     * @param array{action: string, payload?: array<string, mixed>} $request
     * @return Result<mixed, string>
     */
    public function send(array $request): Result
    {
        $blocked = $this->reachability->beforeSend();
        if ($blocked !== null) {
            $this->reportError(ParseError::parse($blocked->error()));
            return $blocked;
        }

        $action = $request['action'];
        $payload = $request['payload'] ?? [];
        $envelope = $this->buildEnvelope($action, $payload);
        $signedPayload = $envelope['payload'];
        $bodyString = json_encode($envelope, JSON_THROW_ON_ERROR);

        return $this->attempt(
            bodyString: $bodyString,
            signedPayload: $signedPayload,
            currentAttempt: 0,
        );
    }

    private function reportError(SdkError $error): void
    {
        $handler = $this->config['onError'] ?? null;
        if (!is_callable($handler)) {
            return;
        }

        Result::try(static fn () => $handler($error));
    }

    private function errorResult(SdkError $error): Result
    {
        $this->reportError($error);

        return Result::err(ParseError::serialize($error));
    }

    /**
     * @param array<string, mixed> $signedPayload
     * @return Result<mixed, string>
     */
    private function attempt(string $bodyString, array $signedPayload, int $currentAttempt): Result
    {
        $headers = $this->buildAuthHeaders($signedPayload);
        $client = $this->config['httpClient'] ?? new CurlHttpClient();
        $timeoutMs = $this->config['timeoutMs'] ?? Config::DEFAULT_TIMEOUT_MS;
        $maxRetries = $this->config['maxRetries'] ?? Config::DEFAULT_MAX_RETRIES;
        $baseUrl = $this->config['baseUrl'] ?? Config::DEFAULT_BASE_URL;

        try {
            $response = $client->post($baseUrl, $bodyString, $headers, $timeoutMs);

            $declaredLength = $response['headers']['content-length'] ?? null;
            if ($declaredLength !== null && (int) $declaredLength > Config::MAX_RESPONSE_BYTES) {
                return $this->errorResult(new SdkError(
                    'internal',
                    'Received an invalid response from the server',
                    false,
                ));
            }

            if (($response['headers']['x-nylon-oversized'] ?? null) === '1') {
                return $this->errorResult(new SdkError(
                    'internal',
                    'Received an invalid response from the server',
                    false,
                ));
            }

            $statusCode = $response['statusCode'];
            if (!in_array($statusCode, Reachability::GATEWAY_DOWN_STATUSES, true)) {
                $this->reachability->noteUp();
            }
            $rawBody = $response['body'];

            if ($statusCode < 200 || $statusCode > 299) {
                $retryable = in_array($statusCode, Config::RETRYABLE_STATUS_CODES, true);
                $errorMessage = 'HTTP ' . $statusCode;

                $decoded = json_decode($rawBody, true);
                if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
                    $errorMessage = $decoded['message'];
                } elseif ($response['reasonPhrase'] !== '') {
                    $errorMessage = $response['reasonPhrase'];
                }

                if ($retryable && $currentAttempt < $maxRetries) {
                    usleep((int) ($this->calculateBackoff($currentAttempt) * 1_000_000));

                    return $this->attempt($bodyString, $signedPayload, $currentAttempt + 1);
                }

                $gatewayReason = Reachability::classifyHttpStatus($statusCode);
                if ($gatewayReason !== null) {
                    $this->reachability->noteDown($gatewayReason);
                    return $this->errorResult(Reachability::sdkError($gatewayReason));
                }

                return $this->errorResult($this->buildHttpError($errorMessage, $statusCode));
            }

            $responseBody = json_decode($rawBody, true);
            if (!is_array($responseBody) || !array_key_exists('status', $responseBody)) {
                return $this->errorResult(new SdkError(
                    'internal',
                    'Received an invalid response from the server',
                    false,
                ));
            }

            $status = $responseBody['status'];
            $message = is_string($responseBody['message'] ?? null) ? $responseBody['message'] : '';
            $data = $responseBody['data'] ?? null;

            if ($status === true) {
                return $this->verifySuccessResponse($data, $headers);
            }

            return $this->errorResult(ParseError::parse($message));
        } catch (\Throwable $error) {
            if ($currentAttempt < $maxRetries) {
                usleep((int) ($this->calculateBackoff($currentAttempt) * 1_000_000));

                return $this->attempt($bodyString, $signedPayload, $currentAttempt + 1);
            }

            $reason = Reachability::classifyError($error->getMessage(), (int) $error->getCode());
            $this->reachability->noteDown($reason);

            return $this->errorResult(new SdkError(
                'network',
                $reason,
                true,
                Reachability::CODE,
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{intent: string, service: string, action: string, payload: array<string, mixed>}
     */
    private function buildEnvelope(string $action, array $payload): array
    {
        return [
            'intent' => 'execute',
            'service' => Config::SDK_SERVICE,
            'action' => $action,
            'payload' => array_merge($payload, ['_fingerprint' => self::$cachedFingerprint]),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function buildAuthHeaders(array $payload): array
    {
        $nonce = Nonce::generate();
        $timestamp = Signature::createTimestamp();
        $signature = Signature::createSignature([
            'fingerprint' => self::$cachedFingerprint,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
            'payload' => $payload,
            'secret' => $this->config['apiSecret'],
        ]);

        return [
            'content-type' => 'application/json',
            // Declares what this client can parse. The backend withholds
            // anything not listed here, so a version that predates a wire
            // addition keeps receiving the shape it was written against. Not
            // covered by the signature (fingerprint + nonce + timestamp +
            // payload), so it is free to change.
            'x-nylon-features' => implode(',', self::CLIENT_FEATURES),
            'x-nylon-key' => $this->config['apiKey'],
            'x-nylon-nonce' => $nonce,
            'x-nylon-signature' => $signature,
            'x-nylon-timestamp' => $timestamp,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return Result<mixed, string>
     */
    private function verifySuccessResponse(mixed $data, array $headers): Result
    {
        [$strippedData, $responseSignature] = $this->stripResponseSignature($data);

        if ($responseSignature === null) {
            return $this->errorResult(new SdkError(
                'internal',
                'Could not verify the server response',
                false,
            ));
        }

        if (!VerifyResponse::verify($strippedData, $responseSignature, $this->config['apiSecret'])) {
            return $this->errorResult(new SdkError(
                'internal',
                'Could not verify the server response',
                false,
            ));
        }

        [$unboundData, $echoedNonce] = $this->stripRequestNonce($strippedData);
        if ($echoedNonce !== ($headers['x-nylon-nonce'] ?? null)) {
            return $this->errorResult(new SdkError(
                'internal',
                'Could not verify the server response',
                false,
            ));
        }

        return Result::ok($unboundData);
    }

    /** @return array{0: mixed, 1: string|null} */
    private function stripRequestNonce(mixed $data): array
    {
        if (!is_array($data) || !array_key_exists('_requestNonce', $data)) {
            return [$data, null];
        }

        $nonce = $data['_requestNonce'];
        unset($data['_requestNonce']);

        return [$data, is_string($nonce) ? $nonce : null];
    }

    /** @return array{0: mixed, 1: string|null} */
    private function stripResponseSignature(mixed $data): array
    {
        if (!is_array($data) || !array_key_exists('_responseSignature', $data)) {
            return [$data, null];
        }

        $signature = $data['_responseSignature'];
        unset($data['_responseSignature']);

        return [$data, is_string($signature) ? $signature : null];
    }

    private function buildHttpError(string $message, int $statusCode): SdkError
    {
        if (preg_match('/^(.*?)\s*--\s*error-type:\s*([a-z_]+)(?:\s*--\s*error-code:\s*([a-z0-9_]+))?\s*$/is', $message, $matches) === 1) {
            $category = $matches[2];
            if (in_array($category, self::KNOWN_CATEGORIES, true)) {
                return new SdkError(
                    $category,
                    $matches[1],
                    in_array($statusCode, Config::RETRYABLE_STATUS_CODES, true),
                    $matches[3] ?? null,
                );
            }
        }

        $category = self::STATUS_CATEGORY[$statusCode]
            ?? ($statusCode >= 500 ? 'internal' : 'validation');

        return new SdkError(
            $category,
            $message,
            in_array($statusCode, Config::RETRYABLE_STATUS_CODES, true),
        );
    }

    private function calculateBackoff(int $attempt): float
    {
        $base = (2 ** $attempt) * 1000;
        $jitter = random_int(0, 500);

        return ($base + $jitter) / 1000;
    }
}
