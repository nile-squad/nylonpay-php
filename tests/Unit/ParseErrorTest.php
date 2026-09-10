<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\ParseError;
use PHPUnit\Framework\TestCase;

final class ParseErrorTest extends TestCase
{
    public function testParseExtractsOptionalErrorCodeSuffix(): void
    {
        $error = ParseError::parse(
            'Amount is below the minimum -- error-type: validation -- error-code: invalid_number'
        );

        self::assertSame('validation', $error->category);
        self::assertSame('Amount is below the minimum', $error->message);
        self::assertSame('invalid_number', $error->code);
    }

    public function testParseKeepsMessagesThatOnlyHaveErrorType(): void
    {
        $error = ParseError::parse('Sign in again -- error-type: auth');

        self::assertSame('auth', $error->category);
        self::assertSame('Sign in again', $error->message);
        self::assertNull($error->code);
    }

    public function testCreateSdkExceptionCarriesCode(): void
    {
        $error = ParseError::parse(
            'Amount is below the minimum -- error-type: validation -- error-code: invalid_number'
        );
        $exception = ParseError::createSdkException($error);

        self::assertSame('validation', $exception->category);
        self::assertSame('invalid_number', $exception->errorCode);
        self::assertSame('Amount is below the minimum', $exception->getMessage());
    }
}
