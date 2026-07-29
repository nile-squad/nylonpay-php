<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Structured SDK error with a fixed category taxonomy.
 *
 * @phpstan-type SdkErrorCategory 'auth'|'validation'|'limit'|'rate_limit'|'account'|'provider'|'duplicate'|'not_found'|'internal'|'network'|'timeout'
 */
final class SdkError
{
    /**
     * @param SdkErrorCategory $category
     */
    public function __construct(
        public readonly string $category,
        public readonly string $message,
        public readonly ?bool $retryable = null,
    ) {
    }

    /** @return array{category: string, message: string, retryable?: bool} */
    public function toArray(): array
    {
        $result = [
            'category' => $this->category,
            'message' => $this->message,
        ];

        if ($this->retryable !== null) {
            $result['retryable'] = $this->retryable;
        }

        return $result;
    }
}
