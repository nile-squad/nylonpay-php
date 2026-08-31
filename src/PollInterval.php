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
        $maxIntervalMs = 15_000;

        // Double per elapsed period, stopping at the cap. `2 ** $periods`
        // overflows int to float once $periods passes 62, which made the
        // return type float on a long-lived poll; doubling in a loop that
        // exits at the cap stays integral for any elapsed time.
        $intervalMs = $baseIntervalMs;
        for ($period = 0; $period < $periods && $intervalMs < $maxIntervalMs; ++$period) {
            $intervalMs *= 2;
        }

        return min($intervalMs, $maxIntervalMs);
    }
}
