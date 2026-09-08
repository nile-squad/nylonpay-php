<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testReturns64LowercaseHexCharacters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Fingerprint::generate());
    }

    public function testIsStableWithinAProcess(): void
    {
        $this->assertSame(Fingerprint::generate(), Fingerprint::generate());
    }

    /**
     * Pin the exact composition. The PHP version and SAPI were removed on
     * 2026-09-08: a runtime version is not obtainable the same way in every SDK
     * and churned the value on every upgrade for no benefit. Recomputing the
     * digest here fails the moment anything is added back, which a length or
     * format check would not catch.
     */
    public function testHashesOnlyOsMetadataWithNoRuntimeVersion(): void
    {
        $expected = hash('sha256', implode('|', [
            'type:' . PHP_OS_FAMILY,
            'platform:' . php_uname('s') . ' ' . php_uname('r'),
            'arch:' . php_uname('m'),
            'release:' . php_uname('r'),
            'hostname:' . gethostname(),
        ]));

        $this->assertSame($expected, Fingerprint::generate());
    }
}
