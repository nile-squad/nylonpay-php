<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Event-driven payment instance returned by async collect/payout operations.
 *
 * @phpstan-type TransactionFetcher callable(array<string, mixed>): Result<array<string, mixed>, string>
 * @phpstan-type InitialResponse array<string, mixed>
 * @phpstan-type PaymentDeps array{
 *   fetchStatus: TransactionFetcher,
 *   fetchTransaction: TransactionFetcher,
 *   pollIntervalMs?: int,
 *   maxPollDurationMs?: int|null,
 *   maxPollAttempts?: int|null,
 *   onDelayed?: 'wait'|'return',
 *   initialError?: SdkError|null
 * }
 */
final class PaymentInstance
{
    /** @var array<string, string> */
    private const STATUS_TO_EVENT = [
        'pending' => 'processing',
        'processing' => 'processing',
        'successful' => 'success',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
    ];

    /** @var list<string> */
    private const TERMINAL_STATES = ['successful', 'failed', 'cancelled'];

    public readonly string $reference;

    public string $status;

    private readonly PubSub $emitter;

    /** @var array<string, mixed>|null */
    private ?array $transaction = null;

    private ?string $lastStatusEvent = null;

    private bool $resolved = false;

    private int $pollAttempts = 0;

    private float $pollStartTime;

    private bool $earlyReturnPending = false;

    private ?SdkError $pendingError = null;

    /**
     * @param InitialResponse $initialResponse
     * @param PaymentDeps     $deps
     */
    public function __construct(array $initialResponse, private readonly array $deps)
    {
        $this->reference = $initialResponse['reference'];
        $this->status = self::normalizeStatus($initialResponse['status']);
        $this->emitter = new PubSub();
        $this->pollStartTime = microtime(true) * 1000;

        if (isset($deps['initialError']) && $deps['initialError'] instanceof SdkError) {
            $this->resolved = true;
            $this->pendingError = $deps['initialError'];
        }
    }

    public function on(string $event, callable $handler): self
    {
        $this->emitter->on($event, $handler);

        return $this;
    }

    public function once(string $event, callable $handler): self
    {
        $this->emitter->once($event, $handler);

        return $this;
    }

    public function off(string $event, callable $handler): self
    {
        $this->emitter->off($event, $handler);

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function wait(): ?array
    {
        if ($this->pendingError !== null) {
            $error = $this->pendingError;
            $this->pendingError = null;
            $this->emitEvent('error', $error->message, $error->category, $error->retryable);

            return null;
        }

        if ($this->resolved) {
            return $this->status === 'successful' ? $this->transaction : null;
        }

        $initialEvent = self::STATUS_TO_EVENT[$this->status] ?? null;
        if ($initialEvent !== null && $initialEvent !== $this->lastStatusEvent) {
            $this->lastStatusEvent = $initialEvent;
            $this->emitEvent($initialEvent);
        }

        if (in_array($this->status, self::TERMINAL_STATES, true)) {
            $this->handleTerminalState($this->status);
        } else {
            while (!$this->resolved) {
                $this->pollOnce();
                if ($this->resolved) {
                    break;
                }

                $interval = PollInterval::resolve(
                    $this->deps['pollIntervalMs'] ?? Config::DEFAULT_MAX_POLL_INTERVAL_MS,
                    $this->pollStartTime,
                );
                usleep((int) (($interval + random_int(0, Config::POLL_JITTER_MS)) * 1000));
            }
        }

        if ($this->earlyReturnPending && $this->transaction !== null) {
            return $this->transaction;
        }

        return $this->status === 'successful' ? $this->transaction : null;
    }

    private function pollOnce(): void
    {
        if ($this->resolved) {
            return;
        }

        $maxAttempts = $this->deps['maxPollAttempts'] ?? null;
        if ($maxAttempts !== null && $this->pollAttempts >= $maxAttempts) {
            $this->emitEvent(
                'error',
                'Timed out waiting for the transaction status to update',
                'timeout',
            );
            $this->resolved = true;

            return;
        }

        $maxDuration = $this->deps['maxPollDurationMs'] ?? null;
        if ($maxDuration !== null && (microtime(true) * 1000 - $this->pollStartTime) >= $maxDuration) {
            $this->emitEvent(
                'error',
                'Timed out waiting for the transaction status to update',
                'timeout',
            );
            $this->resolved = true;

            return;
        }

        $this->pollAttempts++;
        $result = ($this->deps['fetchStatus'])(['reference' => $this->reference]);

        if ($result->isOk()) {
            /** @var array<string, mixed> $response */
            $response = $result->value();
            $this->handleStatusUpdate($response);

            return;
        }

        $parsed = ParseError::parse($result->error());
        if ($parsed->category === 'not_found') {
            return;
        }

        $this->emitEvent('error', $parsed->message, $parsed->category, $parsed->retryable);
        $this->resolved = true;
    }

    /** @param array<string, mixed> $response */
    private function handleStatusUpdate(array $response): void
    {
        if ($this->resolved) {
            return;
        }

        if (($response['reference'] ?? null) !== $this->reference) {
            $this->emitEvent(
                'error',
                'Received a status update for a different transaction',
                'internal',
            );
            $this->resolved = true;

            return;
        }

        $newStatus = self::normalizeStatus((string) ($response['status'] ?? ''));
        $this->status = $newStatus;

        $onDelayed = $this->deps['onDelayed'] ?? 'wait';
        if (
            ($response['delayed'] ?? false) === true
            && $onDelayed === 'return'
            && !in_array($newStatus, self::TERMINAL_STATES, true)
        ) {
            $txResult = ($this->deps['fetchTransaction'])(['reference' => $this->reference]);
            if ($txResult->isOk()) {
                /** @var array<string, mixed> $transaction */
                $transaction = $txResult->value();
                $this->transaction = $transaction;
            }
            $this->earlyReturnPending = true;
            $this->resolved = true;

            return;
        }

        $event = self::STATUS_TO_EVENT[$newStatus] ?? null;
        if ($event === null || $event === $this->lastStatusEvent) {
            return;
        }

        $this->lastStatusEvent = $event;

        if (in_array($newStatus, self::TERMINAL_STATES, true)) {
            $this->handleTerminalState($newStatus);

            return;
        }

        $this->emitEvent($event);
    }

    private function handleTerminalState(string $status): void
    {
        $txResult = ($this->deps['fetchTransaction'])(['reference' => $this->reference]);
        if ($txResult->isOk()) {
            /** @var array<string, mixed> $transaction */
            $transaction = $txResult->value();
            $this->transaction = $transaction;
            $event = self::STATUS_TO_EVENT[$status] ?? null;
            if ($event !== null) {
                $errorMsg = $status === 'failed'
                    ? (is_string($transaction['failureReason'] ?? null) ? $transaction['failureReason'] : null)
                    : null;
                $this->emitEvent($event, $errorMsg);
            }
        } else {
            $this->emitEvent('error', 'Could not retrieve the transaction details');
        }

        $this->resolved = true;
    }

    private function emitEvent(
        string $event,
        ?string $error = null,
        ?string $category = null,
        ?bool $retryable = null,
    ): void {
        $this->emitter->emit($event, [
            'event' => $event,
            'reference' => $this->reference,
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'transaction' => $this->transaction,
            'error' => $error,
            'category' => $category,
            'retryable' => $retryable,
        ]);
    }

    private static function normalizeStatus(string $raw): string
    {
        return $raw === 'completed' ? 'successful' : $raw;
    }
}
