---
name: nylonpay-php
description: Use when integrating Nylon Pay into a server-side PHP app, collecting payments, sending payouts, checking transaction status, verifying phone numbers, creating hosted invoices, or verifying webhook signatures via the nile-squad/nylonpay-php SDK.
---

# Nylon Pay PHP SDK

Server-side SDK for Nylon Pay. PHP 8.1+ with `ext-curl`, `ext-json`,
`ext-openssl`, `ext-mbstring`. Published as `nile-squad/nylonpay-php`.

Integration flows (language-agnostic):
[Nylon Pay integration skill](https://docs.nylonpay.nilesquad.com/docs/skills).
This file covers PHP setup and syntax. Method names and array keys match TypeScript
camelCase. Hub: [nylonpay-overview](https://github.com/nile-squad/nylonpay-overview).

## Setup

```bash
composer require nile-squad/nylonpay-php
```

```php
<?php

require 'vendor/autoload.php';

use function NileSquad\NylonPay\createNylonPay;
use function NileSquad\NylonPay\parseError;

$nylon = createNylonPay([
    'apiKey' => getenv('NYLONPAY_API_KEY'), // must start with "npk_"
    'apiSecret' => getenv('NYLONPAY_API_SECRET'), // must start with "nps_"
]);
```

- Server-side only. Never ship `apiSecret` to a browser or mobile client.
- Test vs live mode comes from the **key**, not a config flag. There is no
  `environment` option.
- Amounts are integers in the currency's smallest tracked unit (for example `10000`).
- Supported currencies: `USD`, `EUR`, `GBP`, `KES`, `UGX`, `TZS`, `RWF`.
- Operations take one associative array.

## Result type, read before writing any call

Sync operations return `Result`. **Always branch on `isOk()` before `value()`.**

```php
$result = $nylon->getStatus([
    'reference' => '550e8400-e29b-41d4-a716-446655440000',
]);

if (!$result->isOk()) {
    $error = parseError($result->error()); // category, message, retryable
    if ($error->retryable) {
        // safe to retry
    }
    return;
}

$status = $result->value();
```

Misconfiguration and client validation throw `SdkException`. Sync API failures
return `Result` errors as strings, use `parseError()`.

## Choosing an operation

| Goal | Use | Shape |
|---|---|---|
| Take money, react to live updates | `collectPayment` | `PaymentInstance` (events) |
| Take money, await final state | `collectPaymentAndResolve` | `Result` |
| Send money, react to live updates | `makePayout` | `PaymentInstance` |
| Send money, await final state | `makePayoutAndResolve` | `Result` |
| One-shot status | `getStatus` | `Result` |
| Full transaction record | `getTransaction` | `Result` |
| Pre-validate phone / get name | `verifyPhone` | `Result` |
| Hosted payment link (cards) | `createInvoice` | `Result` with `paymentLink` |
| Authenticate webhook | `verifyWebhookSignature` | `bool` |

Prefer `*AndResolve` for simple request/response flows.

## Event-driven flow

```php
$payment = $nylon->collectPayment([
    'amount' => 10000,
    'currency' => 'UGX',
    'customer' => [
        'name' => 'Jane',
        'phoneNumber' => '+256700000000',
    ],
    'description' => 'Order #1234',
    'method' => 'mobileMoney',
    // optional; omit to auto-generate a UUID v4
    'reference' => '550e8400-e29b-41d4-a716-446655440000',
]);

$payment->on('success', function (array $data): void {
    // $data['transaction']
});

$payment->on('failed', function (array $data): void {
    // $data['error']
});

$tx = $payment->wait(); // transaction or null, does not throw on failure
```

Events: `processing`, `success`, `failed`, `cancelled`, `error`.
Also: `once`, `off`, `wait`.

## Webhooks

Verify on the **raw request body**:

```php
use function NileSquad\NylonPay\verifyWebhookSignature;

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_NYLON_SIGNATURE'] ?? '';

$valid = verifyWebhookSignature([
    'payload' => $rawBody,
    'signature' => $signature,
    'secret' => getenv('NYLONPAY_WEBHOOK_SECRET'),
]);

if (!$valid) {
    http_response_code(401);
    exit;
}
```

Verification never throws, it returns `false` on any failure.

## Gotchas

- Use the raw, unparsed body for `verifyWebhookSignature`.
- Card payments only via hosted `createInvoice` (read `paymentLink`).
- Idempotency: pass a stable UUID `reference` you own, or omit it for an
  auto-generated UUID v4. Non-UUID values throw a validation error.
- Array keys use camelCase (`phoneNumber`, `apiKey`, `paymentLink`), same as
  TypeScript, not Python snake_case.
- Spec: [Nylon Pay SDK Spec](https://github.com/nile-squad/specs/blob/main/nylonpay-sdk-spec/spec.md).

## Other language SDKs

| Language | Package | SDK skill |
|---|---|---|
| TypeScript | [`@nile-squad/nylonpay-ts`](https://github.com/nile-squad/nylonpay-ts) | [SKILL.md](https://github.com/nile-squad/nylonpay-ts/blob/main/SKILL.md) |
| Python | [`nylonpay-py`](https://github.com/nile-squad/nylonpay-py) | [SKILL.md](https://github.com/nile-squad/nylonpay-py/blob/main/SKILL.md) |

Integration skill: [docs](https://docs.nylonpay.nilesquad.com/docs/skills).
Example prompts: [docs](https://docs.nylonpay.nilesquad.com/docs/skills/example-prompts).
Hub: [nylonpay-overview](https://github.com/nile-squad/nylonpay-overview).
