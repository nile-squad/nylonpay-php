<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Remember the last successful round-trip. A recent success (5 minutes)
 * skips the down-check so status polls do not hammer the server. After an
 * unreachable failure, the next SDK operation is checked before it is
 * attempted.
 */
final class Reachability
{
    public const HOST_OFFLINE = 'host has no internet connection';

    public const NYLON_DOWN = 'Nylon Pay services seem to be down';

    public const CODE = 'unreachable';

    /** @var list<int> */
    public const GATEWAY_DOWN_STATUSES = [502, 503, 504];

    /** curl: could not resolve host / proxy */
    private const CURL_HOST_OFFLINE = [5, 6];

    /** @var callable(): int */
    private $now;

    /** @var (callable(): ?string)|null */
    private $probe;

    private int $successFreshMs;

    private int $downRecheckMs;

    private ?int $lastSuccessAt = null;

    private bool $lastFailed = false;

    private ?string $lastReason = null;

    private ?int $lastCheckAt = null;

    public function __construct(
        ?callable $now = null,
        ?callable $probe = null,
        int $successFreshMs = Config::REACHABILITY_SUCCESS_FRESH_MS,
        int $downRecheckMs = Config::REACHABILITY_DOWN_RECHECK_MS,
    ) {
        $this->now = $now ?? static fn (): int => (int) round(microtime(true) * 1000);
        $this->probe = $probe;
        $this->successFreshMs = $successFreshMs;
        $this->downRecheckMs = $downRecheckMs;
    }

    public static function classifyError(string $message, int $curlErrno = 0): string
    {
        if (in_array($curlErrno, self::CURL_HOST_OFFLINE, true)) {
            return self::HOST_OFFLINE;
        }

        $text = strtolower($message);
        $offlineNeedles = [
            'enotfound',
            'could not resolve host',
            "couldn't resolve host",
            'failed to resolve',
            'name or service not known',
            'nodename nor servname',
            'no such host',
            'getaddrinfo',
        ];
        foreach ($offlineNeedles as $needle) {
            if (str_contains($text, $needle)) {
                return self::HOST_OFFLINE;
            }
        }

        return self::NYLON_DOWN;
    }

    public static function classifyHttpStatus(int $statusCode): ?string
    {
        return in_array($statusCode, self::GATEWAY_DOWN_STATUSES, true)
            ? self::NYLON_DOWN
            : null;
    }

    /**
     * @return Result<mixed, string>|null
     */
    public function beforeSend(): ?Result
    {
        $t = ($this->now)();
        $lastCheck = $this->lastCheckAt;
        $lastSuccess = $this->lastSuccessAt;

        if ($lastCheck !== null && ($t - $lastCheck) >= $this->successFreshMs) {
            return $this->runCheck();
        }

        if (
            !$this->lastFailed
            && $lastSuccess !== null
            && ($t - $lastSuccess) < $this->successFreshMs
        ) {
            return null;
        }

        if ($this->lastFailed) {
            if (
                $lastCheck !== null
                && ($t - $lastCheck) < $this->downRecheckMs
            ) {
                return Result::err(ParseError::serialize(self::sdkError(
                    $this->lastReason ?? self::NYLON_DOWN,
                )));
            }

            return $this->runCheck();
        }

        if ($lastSuccess === null) {
            return null;
        }

        return $this->runCheck();
    }

    public function noteDown(string $reason): void
    {
        $this->lastFailed = true;
        $this->lastReason = $reason;
        $this->lastCheckAt = ($this->now)();
    }

    public function noteUp(): void
    {
        $t = ($this->now)();
        $this->lastSuccessAt = $t;
        $this->lastCheckAt = $t;
        $this->lastFailed = false;
        $this->lastReason = null;
    }

    public static function sdkError(string $reason): SdkError
    {
        return new SdkError('network', $reason, true, self::CODE);
    }

    /**
     * @return Result<mixed, string>|null
     */
    private function runCheck(): ?Result
    {
        $this->lastCheckAt = ($this->now)();
        if ($this->probe === null) {
            return null;
        }

        $reason = ($this->probe)();
        if ($reason === null) {
            $this->noteUp();

            return null;
        }

        $this->lastFailed = true;
        $this->lastReason = $reason;

        return Result::err(ParseError::serialize(self::sdkError($reason)));
    }
}
