<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Structured SDK error. Merchants branch on `$reason`.
 *
 * @phpstan-type SdkErrorReason 'AUTH'|'VALIDATION'|'LIMIT'|'RATE_LIMIT'|'ACCOUNT'|'PROVIDER'|'DUPLICATE'|'NOT_FOUND'|'INTERNAL'|'NETWORK'|'SERVICES_DOWN'|'TIMEOUT'
 * @phpstan-type SdkErrorCategory 'auth'|'validation'|'limit'|'rate_limit'|'account'|'provider'|'duplicate'|'not_found'|'internal'|'network'|'timeout'
 */
final class SdkError
{
    /** @var list<SdkErrorReason> */
    public const REASONS = [
        'AUTH',
        'VALIDATION',
        'LIMIT',
        'RATE_LIMIT',
        'ACCOUNT',
        'PROVIDER',
        'DUPLICATE',
        'NOT_FOUND',
        'INTERNAL',
        'NETWORK',
        'SERVICES_DOWN',
        'TIMEOUT',
    ];

    /**
     * Literal keys double as the set of categories the wire is allowed to
     * send, so an unknown category can be narrowed out at the call site.
     *
     * @var array{auth: 'AUTH', validation: 'VALIDATION', limit: 'LIMIT', rate_limit: 'RATE_LIMIT', account: 'ACCOUNT', provider: 'PROVIDER', duplicate: 'DUPLICATE', not_found: 'NOT_FOUND', internal: 'INTERNAL', network: 'NETWORK', timeout: 'TIMEOUT'}
     */
    private const CATEGORY_TO_REASON = [
        'auth' => 'AUTH',
        'validation' => 'VALIDATION',
        'limit' => 'LIMIT',
        'rate_limit' => 'RATE_LIMIT',
        'account' => 'ACCOUNT',
        'provider' => 'PROVIDER',
        'duplicate' => 'DUPLICATE',
        'not_found' => 'NOT_FOUND',
        'internal' => 'INTERNAL',
        'network' => 'NETWORK',
        'timeout' => 'TIMEOUT',
    ];

    /** @var array<SdkErrorReason, SdkErrorCategory> */
    private const REASON_TO_CATEGORY = [
        'AUTH' => 'auth',
        'VALIDATION' => 'validation',
        'LIMIT' => 'limit',
        'RATE_LIMIT' => 'rate_limit',
        'ACCOUNT' => 'account',
        'PROVIDER' => 'provider',
        'DUPLICATE' => 'duplicate',
        'NOT_FOUND' => 'not_found',
        'INTERNAL' => 'internal',
        'NETWORK' => 'network',
        'SERVICES_DOWN' => 'network',
        'TIMEOUT' => 'timeout',
    ];

    /**
     * @var SdkErrorReason
     */
    public readonly string $reason;

    /**
     * @deprecated Use $reason. Lowercase wire category kept this release.
     * @var SdkErrorCategory
     */
    public readonly string $category;

    /**
     * @param SdkErrorReason $reason
     * @param SdkErrorCategory|null $category
     */
    public function __construct(
        string $reason,
        public readonly string $message,
        public readonly ?bool $retryable = null,
        public readonly ?string $code = null,
        ?string $category = null,
    ) {
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : 'INTERNAL';
        $this->category = $category ?? self::REASON_TO_CATEGORY[$this->reason];
    }

    /**
     * @param array{reason?: string|null, category?: string|null, message: string, retryable?: bool|null, code?: string|null} $params
     */
    public static function from(array $params): self
    {
        $message = $params['message'];
        $code = $params['code'] ?? null;
        $reason = self::resolveReason(
            $params['reason'] ?? null,
            $params['category'] ?? null,
            $message,
            is_string($code) ? $code : null,
        );
        $resolvedCode = $code;
        if ($resolvedCode === null && ($reason === 'NETWORK' || $reason === 'SERVICES_DOWN')) {
            $resolvedCode = Config::UNREACHABLE_CODE;
        }

        $category = $params['category'] ?? null;
        $knownCategory = is_string($category) && isset(self::CATEGORY_TO_REASON[$category])
            ? $category
            : null;

        return new self(
            $reason,
            $message,
            $params['retryable'] ?? null,
            $resolvedCode,
            $knownCategory,
        );
    }

    /** @return array{reason: string, category: string, message: string, retryable?: bool, code?: string} */
    public function toArray(): array
    {
        $result = [
            'reason' => $this->reason,
            'category' => $this->category,
            'message' => $this->message,
        ];

        if ($this->retryable !== null) {
            $result['retryable'] = $this->retryable;
        }

        if ($this->code !== null) {
            $result['code'] = $this->code;
        }

        return $result;
    }

    /**
     * @return SdkErrorReason
     */
    public static function resolveReason(
        ?string $reason,
        ?string $category,
        string $message,
        ?string $code,
    ): string {
        if ($reason !== null && in_array($reason, self::REASONS, true)) {
            return $reason;
        }

        if ($code === Config::UNREACHABLE_CODE || $message === Config::UNREACHABLE_HOST_OFFLINE) {
            if ($message === Config::UNREACHABLE_HOST_OFFLINE) {
                return 'NETWORK';
            }
            if ($message === Config::UNREACHABLE_NYLON_DOWN) {
                return 'SERVICES_DOWN';
            }
        }

        if ($message === Config::UNREACHABLE_NYLON_DOWN) {
            return 'SERVICES_DOWN';
        }

        if ($category !== null && isset(self::CATEGORY_TO_REASON[$category])) {
            return self::CATEGORY_TO_REASON[$category];
        }

        return 'INTERNAL';
    }
}
