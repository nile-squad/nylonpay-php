<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Lightweight pub/sub emitter for payment lifecycle events.
 */
final class PubSub
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    private const MAX_HANDLERS_PER_EVENT = 1000;

    public function on(string $event, callable $handler): callable
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        if (count($this->listeners[$event]) >= self::MAX_HANDLERS_PER_EVENT) {
            return fn (): bool => $this->off($event, $handler);
        }

        $this->listeners[$event][] = $handler;

        return fn (): bool => $this->off($event, $handler);
    }

    public function once(string $event, callable $handler): void
    {
        $wrapper = function (mixed $data) use ($event, $handler, &$wrapper): void {
            $this->off($event, $wrapper);
            $handler($data);
        };

        $this->on($event, $wrapper);
    }

    public function off(string $event, callable $handler): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        foreach ($this->listeners[$event] as $index => $registered) {
            if ($registered === $handler) {
                unset($this->listeners[$event][$index]);

                return true;
            }
        }

        return false;
    }

    public function emit(string $event, mixed $data): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $handler) {
            Result::try(static fn () => $handler($data));
        }
    }

    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }
}
