<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Operational defaults and wire constants shared across SDK implementations.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.nylonpay.nilesquad.com/api/services';

    public const DEFAULT_TIMEOUT_MS = 90_000;

    public const DEFAULT_MAX_RETRIES = 3;

    public const DEFAULT_MAX_POLL_INTERVAL_MS = 2_000;

    public const POLL_JITTER_MS = 250;

    public const SDK_SERVICE = 'sdk';

    public const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    public const MIN_COLLECTION_AMOUNT = 500;

    public const MIN_DISBURSEMENT_AMOUNT = 5000;

    /** @var list<int> */
    public const RETRYABLE_STATUS_CODES = [408, 429, 500, 502, 503, 504];

    /** @var array<string, string> */
    public const SDK_ACTIONS = [
        'collectPayment' => 'sdk-collect-payment',
        'collectPaymentAndResolve' => 'sdk-collect-payment-and-resolve',
        'makePayout' => 'sdk-make-payout',
        'makePayoutAndResolve' => 'sdk-make-payout-and-resolve',
        'getStatus' => 'sdk-get-status',
        'getTransaction' => 'sdk-get-transaction',
        'listTransactions' => 'sdk-list-transactions',
        'verifyPhone' => 'sdk-verify-phone',
        'createInvoice' => 'sdk-create-invoice',
    ];
}
