<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Block until a payment reaches a terminal state.
 */
final class PollUntilTerminal
{
    private const TIMEOUT_MESSAGE = 'Timed out waiting for the transaction status to update';

    /**
     * @param array{
     *   reference: string,
     *   fetchStatus: callable(array): Result,
     *   fetchTransaction: callable(array): Result,
     *   pollIntervalMs: int,
     *   maxPollDurationMs?: int|null,
     *   maxPollAttempts?: int|null,
     *   onDelayed?: 'wait'|'return'
     * } $deps
     * @return Result<array<string, mixed>, string>
     */
    public static function run(array $deps): Result
    {
        $pollStart = microtime(true) * 1000;
        $attempts = 0;
        $reference = $deps['reference'];
        $fetchStatus = $deps['fetchStatus'];
        $fetchTransaction = $deps['fetchTransaction'];
        $pollIntervalMs = $deps['pollIntervalMs'];
        $maxPollDurationMs = $deps['maxPollDurationMs'] ?? null;
        $maxPollAttempts = $deps['maxPollAttempts'] ?? null;
        $onDelayed = $deps['onDelayed'] ?? 'wait';

        while (true) {
            if ($maxPollAttempts !== null && $attempts >= $maxPollAttempts) {
                return Result::err(self::TIMEOUT_MESSAGE);
            }

            if ($maxPollDurationMs !== null && (microtime(true) * 1000 - $pollStart) >= $maxPollDurationMs) {
                return Result::err(self::TIMEOUT_MESSAGE);
            }

            $attempts++;
            /** @var Result $statusResult */
            $statusResult = $fetchStatus(['reference' => $reference]);

            if ($statusResult->isErr()) {
                $parsed = ParseError::parse($statusResult->error());
                if ($parsed->category === 'not_found') {
                    usleep($pollIntervalMs * 1000);
                    continue;
                }

                return Result::err($parsed->message);
            }

            /** @var array<string, mixed> $status */
            $status = $statusResult->value();

            if (PollInterval::isTerminal((string) ($status['status'] ?? ''))) {
                /** @var Result $txResult */
                $txResult = $fetchTransaction(['reference' => $reference]);

                return $txResult->isOk() ? $txResult : Result::err($txResult->error());
            }

            if (($status['delayed'] ?? false) === true && $onDelayed === 'return') {
                /** @var Result $txResult */
                $txResult = $fetchTransaction(['reference' => $reference]);

                return $txResult->isOk() ? $txResult : Result::err($txResult->error());
            }

            $interval = PollInterval::resolve($pollIntervalMs, $pollStart);
            usleep((int) (($interval + random_int(0, Config::POLL_JITTER_MS)) * 1000));
        }
    }
}
