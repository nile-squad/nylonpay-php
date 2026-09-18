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
        self::assertSame('254710000000', Phone::normalize('+254710000000'));
        self::assertSame('255712345678', Phone::normalize('0712345678', 'TZS'));
        self::assertSame('250781234567', Phone::normalize('0781234567', 'RWF'));
        self::assertSame('243812345678', Phone::normalize('0812345678', 'CDF'));
        self::assertSame('260763456789', Phone::normalize('0763456789', 'ZMW'));
        self::assertSame('237671234567', Phone::normalize('0671234567', 'XAF'));
    }

    public function testValidFormat(): void
    {
        self::assertTrue(Phone::isValidFormat('256768499027'));
        self::assertFalse(Phone::isValidFormat('abc'));
    }
}
