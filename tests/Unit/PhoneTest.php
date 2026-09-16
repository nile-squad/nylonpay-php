<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    public function testNormalizeLocalUgandanNumber(): void
    {
        self::assertSame('256768499027', Phone::normalize('0768499027'));
        self::assertSame('254710000000', Phone::normalize('0710000000', 'KES'));
    }

    public function testValidFormat(): void
    {
        self::assertTrue(Phone::isValidFormat('256768499027'));
        self::assertFalse(Phone::isValidFormat('abc'));
    }
}
