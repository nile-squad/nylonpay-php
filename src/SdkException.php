<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Exception for misconfiguration and client-side validation failures.
 */
final class SdkException extends \RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?bool $retryable = null,
    ) {
        parent::__construct($message);
    }
}
