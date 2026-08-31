<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Public entry points for merchants integrating the PHP SDK.
 *
 * @param array{
 *   apiKey: string,
 *   apiSecret: string,
 *   baseUrl?: string,
 *   timeoutMs?: int,
 *   maxRetries?: int,
 *   maxPollIntervalMs?: int,
 *   maxPollDurationMs?: int|null,
 *   maxPollAttempts?: int|null,
 *   onDelayed?: 'wait'|'return',
 *   force?: bool,
 *   httpClient?: HttpClient|null,
 *   hooks?: array<string, mixed>|null
 * } $config
 */
function createNylonPay(array $config): NylonPay
{
    return CreateNylonPay::create($config);
}

function parseError(string $error): SdkError
{
    return ParseError::parse($error);
}

/**
 * @param array{
 *   payload: string|resource,
 *   signature: string,
 *   secret: string,
 *   toleranceSeconds?: int|null
 * } $input
 */
function verifyWebhookSignature(array $input): bool
{
    return VerifyWebhook::verify($input);
}

const DISABLE_FRESHNESS_CHECK = VerifyWebhook::DISABLE_FRESHNESS_CHECK;
