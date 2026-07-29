<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testTryCapturesException(): void
    {
        $result = Result::try(static function (): never {
            throw new \RuntimeException('boom');
        });

        self::assertTrue($result->isErr());
        self::assertInstanceOf(\RuntimeException::class, $result->error());
    }
}
