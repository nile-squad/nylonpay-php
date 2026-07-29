<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Result type separating operational errors from success values.
 *
 * @template T
 * @template E
 */
final class Result
{
    /** @param T|null $value */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value = null,
        private readonly mixed $error = null,
    ) {
    }

    /** @param T $value @return Result<T, E> */
    public static function ok(mixed $value): self
    {
        return new self(true, $value);
    }

    /** @param E $error @return Result<T, E> */
    public static function err(mixed $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Wrap a callable — returns Ok(value) or Err(exception). Never throws.
     *
     * @template U
     * @param callable(): U $fn
     * @return Result<U, \Throwable>
     */
    public static function try(callable $fn): self
    {
        try {
            return self::ok($fn());
        } catch (\Throwable $error) {
            return self::err($error);
        }
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isErr(): bool
    {
        return !$this->ok;
    }

    /** @return T */
    public function value(): mixed
    {
        return $this->value;
    }

    /** @return E */
    public function error(): mixed
    {
        return $this->error;
    }
}
