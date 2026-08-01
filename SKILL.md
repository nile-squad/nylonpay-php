---
name: nylonpay-php
description: Use when integrating Nylon Pay into a server-side PHP app — collecting payments, sending payouts, checking transaction status, verifying phone numbers, creating hosted invoices, or verifying webhook signatures via the nile-squad/nylonpay-php SDK.
---

# Nylon Pay PHP SDK

Server-side SDK for Nylon Pay. PHP 8.1+ with `ext-curl`, `ext-json`,
`ext-openssl`, `ext-mbstring`. Published as `nile-squad/nylonpay-php`.

## Setup

```bash
composer require nile-squad/nylonpay-php
```

```php
<?php

require 'vendor/autoload.php';

use function NileSquad\NylonPay\createNylonPay;

$nylon = createNylonPay([
    'apiKey' => getenv('NYLONPAY_API_KEY'),       // must start with "npk_"
    'apiSecret' => getenv('NYLONPAY_API_SECRET'), // must start with "nps_"
]);
```

- This is a **server-side** SDK. Never ship `apiSecret` to a browser or mobile client.
- Test vs. live mode is decided by the **key**, not a config flag. There is no
  `environment` option.
- Amounts are integers in the currency's smallest tracked unit (e.g. `10000`).
- Supported currencies: `USD`, `EUR`, `GBP`, `KES`, `UGX`, `TZS`, `RWF`.
- Operations take one associative array. Method names match the cross-language
  spec (camelCase, same as TypeScript).

## Result type — read before writing any call

Sync operations return `Result`. **Always branch on `isOk()` before `value()`.**

```php
use function NileSquad\NylonPay\parseError;

$result = $nylon->getStatus(['reference' => 'ORDER-2026-001']);

if (!$result->isOk()) {
    $error = parseError($result->error()); // category, message, retryable
    if ($error->retryable) {
        // safe to retry
    }
    return;
}

$status = $result->value();
```

Misconfiguration / client validation throws `SdkException`. Sync API failures
return `Result` errors as strings — use `parseError()`.

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
| Hosted payment link (cards) | `createInvoice` | `Result` with URL |
| Authenticate webhook | `verifyWebhookSignature` | `bool` |

**Prefer `*AndResolve`** for simple request/response flows.

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
    'reference' => 'ORDER-2026-001', // optional; 13–15 chars if supplied
]);

$payment->on('success', function (array $data): void {
    // $data['transaction']
});

$payment->on('failed', function (array $data): void {
    // $data['error']
});

$tx = $payment->wait();
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

Verification never throws — returns `false` on any failure.

## Gotchas

- Use the raw, unparsed body for `verifyWebhookSignature`.
- Card payments only via hosted `createInvoice`.
- Stable `reference` for idempotency: **13 to 15 characters** if you supply one.
- Array keys use camelCase (`phoneNumber`, `apiKey`) — same as TypeScript, not Python snake_case.
- Spec: [Nylon Pay SDK Spec](https://github.com/nile-squad/specs/blob/main/nylonpay-sdk-spec/spec.md).
