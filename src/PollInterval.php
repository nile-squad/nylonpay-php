<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Poll interval backoff for long-running status checks.
 */
final class PollInterval
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = ['successful', 'failed', 'cancelled', 'completed'];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    public static function resolve(int $baseIntervalMs, float $pollStartTimeMs, ?float $nowMs = null): int
    {
        $now = $nowMs ?? microtime(true) * 1000;
        $elapsed = $now - $pollStartTimeMs;
        $twoMinutes = 2 * 60 * 1000;

        if ($elapsed < $twoMinutes) {
            return $baseIntervalMs;
        }

        $periods = (int) floor(($elapsed - $twoMinutes) / $twoMinutes) + 1;

        return min($baseIntervalMs * (2 ** $periods), 15_000);
    }
}
