# nylonpay-php

Server-side PHP SDK for integrating Nylon Pay payment operations into your application.

[Full documentation](https://docs.nylonpay.nilesquad.com/docs)

## Install

```bash
composer require nile-squad/nylonpay-php
```

Requires PHP 8.1+ with `ext-curl`, `ext-json`, `ext-openssl`, and `ext-mbstring`.

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use function NileSquad\NylonPay\createNylonPay;

$nylon = createNylonPay([
    'apiKey' => 'npk_test_...',
    'apiSecret' => 'nps_test_...',
]);

$payment = $nylon->collectPayment([
    'amount' => 10000,
    'currency' => 'UGX',
    'customer' => [
        'name' => 'Jane',
        'phoneNumber' => '+256700000000',
    ],
    'description' => 'Order #1234',
]);

$payment->on('success', function (array $data): void {
    echo 'Paid: ' . ($data['transaction']['reference'] ?? '') . PHP_EOL;
});

$payment->on('failed', function (array $data): void {
    echo 'Failed: ' . ($data['error'] ?? '') . PHP_EOL;
});

$tx = $payment->wait();
```

## Configuration

| Field | Required | Default | Description |
|---|---|---|---|
| `apiKey` | Yes | | Must start with `npk_` |
| `apiSecret` | Yes | | Must start with `nps_` |
| `baseUrl` | No | `https://api.nylonpay.nilesquad.com/api/services` | Override endpoint |
| `timeoutMs` | No | `90000` | Request timeout in milliseconds |
| `maxRetries` | No | `3` | Retry count for failed requests |
| `maxPollIntervalMs` | No | `2000` | Interval between status checks |
| `maxPollDurationMs` | No | *(none)* | Optional cap on total wait time |
| `maxPollAttempts` | No | *(none)* | Optional cap on status checks |
| `onDelayed` | No | `"wait"` | `"return"` or `"wait"` when delayed |
| `force` | No | `false` | Bypass instance cache |
| `hooks` | No | `null` | Lifecycle hooks |
| `httpClient` | No | `null` | Injectable HTTP client for tests |

The factory caches instances by `apiKey + baseUrl + sha256(apiSecret)`. Rotating the secret yields a fresh instance.

## Operations

All operations take a single associative array. Method names match the cross-language SDK spec.

- `collectPayment` — returns `PaymentInstance`
- `collectPaymentAndResolve` — blocks until terminal, returns `Result`
- `makePayout` — returns `PaymentInstance`
- `makePayoutAndResolve` — blocks until terminal, returns `Result`
- `getStatus` — one-shot status check
- `getTransaction` — full transaction lookup
- `listTransactions` — paginated list with optional filters
- `getTransactionsByTag` — shorthand tag filter
- `verifyPhone` — pre-validate a phone number
- `createInvoice` — hosted invoice link
- `verifyWebhookSignature` — HMAC verification utility

### Sync result example

```php
use function NileSquad\NylonPay\parseError;

$result = $nylon->getStatus(['reference' => $payment->reference]);

if ($result->isOk()) {
    $status = $result->value();
} else {
    $error = parseError($result->error());
    // $error->category, $error->message, $error->retryable
}
```

## PaymentInstance lifecycle

Events: `processing`, `success`, `failed`, `cancelled`, `error`.

```php
$payment->on('success', fn (array $data) => /* ... */);
$payment->once('failed', fn (array $data) => /* ... */);
$payment->off('success', $handler);
$tx = $payment->wait();
```

Polling runs inside `wait()`. If initiation fails at the server, the instance emits `"error"` when `wait()` is called.

## Webhooks

```php
use function NileSquad\NylonPay\verifyWebhookSignature;

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_NYLON_SIGNATURE'] ?? '';

$valid = verifyWebhookSignature([
    'payload' => $rawBody,
    'signature' => $signature,
    'secret' => getenv('NYLON_WEBHOOK_SECRET'),
]);

if (!$valid) {
    http_response_code(401);
    exit;
}
```

Verification never throws — it returns `false` on any failure.

## Errors

- Misconfiguration and client validation throw `SdkException` with `category`.
- Sync operations return `Result` errors as JSON-serialized strings — use `parseError()`.
- Branch on `category`, never message text.

Categories: `auth`, `validation`, `limit`, `rate_limit`, `account`, `provider`, `duplicate`, `not_found`, `internal`, `network`, `timeout`.

## Development

Requires PHP 8.2+ with `curl`, `json`, `mbstring`, `openssl`, and Composer.

```bash
composer install
composer test              # unit + security
composer test:integration  # I1–I19 against sandbox (.env)
composer check             # cs-check + phpstan + tests
```

Integration tests load credentials from `packages/sdks/php/.env`, or fall back to `packages/sdks/typescript/.env`:

```bash
NYLONPAY_API_KEY=npk_test_...
NYLONPAY_API_SECRET=nps_test_...
NYLONPAY_BASE_URL=http://localhost:8000/api/services
```

**Publish gate:** run `composer check` and the full integration suite against sandbox before any Packagist release.

## Related SDKs

- [PHP SDK](https://github.com/nile-squad/nylonpay-php) (this package)
- [TypeScript SDK](https://github.com/nile-squad/nylonpay-ts)
- [Python SDK](https://github.com/nile-squad/nylonpay-py)
- [SDK Spec](https://github.com/nile-squad/specs)

## License

MIT
