<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\PaymentInstance;
use NileSquad\NylonPay\Result;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaymentInstanceStatusTest extends TestCase
{
    /** @return array{fetchStatus: callable, fetchTransaction: callable} */
    private function unusedDeps(): array
    {
        $unused = static fn (): Result => Result::err('unused');

        return [
            'fetchStatus' => $unused,
            'fetchTransaction' => $unused,
        ];
    }

    public function testUnderReviewNormalizesToOnHold(): void
    {
        $instance = new PaymentInstance(
            ['reference' => 'ref-1', 'status' => 'under_review'],
            $this->unusedDeps(),
        );

        self::assertSame('on_hold', $instance->status);
    }

    public function testOnHoldMapsToTheProcessingEvent(): void
    {
        $reflection = new ReflectionClass(PaymentInstance::class);
        $map = $reflection->getConstant('STATUS_TO_EVENT');

        self::assertIsArray($map);
        self::assertSame('processing', $map['on_hold']);
    }
}
