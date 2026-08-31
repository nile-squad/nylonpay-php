<?php

declare(strict_types=1);

namespace NileSquad\NylonPay;

/**
 * Result type separating operational errors from success values.
 *
 * @template-covariant T
 * @template-covariant E
 */
final class Result
{
    /**
     * Deliberately not annotated with T and E. Annotating the value and error
     * parameters binds the unused side of a factory call to the null default,
     * so ok() would infer E as null and err() would infer T as null. The
     * public contract lives on the factories and the accessors instead.
     */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value = null,
        private readonly mixed $error = null,
    ) {
    }

    /**
     * @template TValue
     *
     * @param TValue $value
     *
     * @return self<TValue, never>
     */
    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    /**
     * @template TError
     *
     * @param TError $error
     *
     * @return self<never, TError>
     */
    public static function err(mixed $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Wrap a callable, returning Ok(value) or Err(exception). Never throws.
     *
     * @template U
     *
     * @param callable(): U $fn
     *
     * @return self<U, \Throwable>
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

    /**
     * The success value. Valid only on an Ok result carrying a value.
     *
     * Guard with isOk() before calling. This used to return null on an Err,
     * which let "no value" travel onward looking like data, in an SDK whose
     * values are transactions and amounts. It now fails loudly instead.
     *
     * @return T
     *
     * @throws \LogicException when the result is an Err, or carries no value
     */
    public function value(): mixed
    {
        if (!$this->ok) {
            throw new \LogicException(
                'Result::value() called on an Err result. Check isOk() first, and read error() instead.'
            );
        }

        if (null === $this->value) {
            throw new \LogicException('Result::value() called on an Ok result carrying no value.');
        }

        return $this->value;
    }

    /**
     * The failure value. Valid only on an Err result.
     *
     * @return E
     *
     * @throws \LogicException when the result is an Ok, or carries no error
     */
    public function error(): mixed
    {
        if ($this->ok) {
            throw new \LogicException(
                'Result::error() called on an Ok result. Check isErr() first, and read value() instead.'
            );
        }

        if (null === $this->error) {
            throw new \LogicException('Result::error() called on an Err result carrying no error.');
        }

        return $this->error;
    }
}
