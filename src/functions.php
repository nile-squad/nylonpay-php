<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Public entry points for merchants integrating the PHP SDK.
 *
 * @param array<string, mixed> $config
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
