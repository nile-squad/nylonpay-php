<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testNestedKeyOrderIndependent(): void
    {
        $a = Signature::createCanonicalPayload(['outer' => ['z' => 1, 'a' => 2], 'first' => 0]);
        $b = Signature::createCanonicalPayload(['first' => 0, 'outer' => ['a' => 2, 'z' => 1]]);

        self::assertSame($a, $b);
    }

    public function testArrayOrderSignificant(): void
    {
        $a = Signature::createCanonicalPayload([1, 2, 3]);
        $b = Signature::createCanonicalPayload([3, 2, 1]);

        self::assertNotSame($a, $b);
        self::assertSame('[1,2,3]', $a);
    }
}
