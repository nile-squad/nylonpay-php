<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Exception for misconfiguration and client-side validation failures.
 */
final class SdkException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?bool $retryable = null,
        /**
         * @deprecated Use $reason.
         */
        public readonly ?string $category = null,
        // Named errorCode, Exception already owns integer $code.
        /**
         * @deprecated Use $reason.
         */
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
