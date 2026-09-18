<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\ParseError;
use NileSquad\NylonPay\Reachability;
use NileSquad\NylonPay\Tests\Support\MockHttpClient;
use NileSquad\NylonPay\Transport;
use PHPUnit\Framework\TestCase;

final class ReachabilityTest extends TestCase
{
    public function testDnsMessageIsHostOffline(): void
    {
        self::assertSame(
            Reachability::HOST_OFFLINE,
            Reachability::classifyError('Could not resolve host: api.nylonpay.nilesquad.com', 6),
        );
    }

    public function testConnectionRefusedIsNylonDown(): void
    {
        self::assertSame(
            Reachability::NYLON_DOWN,
            Reachability::classifyError('Failed to connect to host', 7),
        );
    }

    public function testGatewayStatuses(): void
    {
        self::assertSame(Reachability::NYLON_DOWN, Reachability::classifyHttpStatus(502));
        self::assertSame(Reachability::NYLON_DOWN, Reachability::classifyHttpStatus(503));
        self::assertNull(Reachability::classifyHttpStatus(400));
        self::assertNull(Reachability::classifyHttpStatus(500));
    }

    public function testDoesNotCheckWhileRecentSuccessIsFresh(): void
    {
        $now = 1000;
        $probes = 0;
        $tracker = new Reachability(
            null,
            static function () use (&$now): int {
                return $now;
            },
            static function () use (&$probes): ?string {
                $probes++;

                return null;
            },
            5 * 60 * 1000,
            15_000,
        );

        self::assertNull($tracker->beforeSend());
        self::assertSame(0, $probes);

        $tracker->noteUp();
        $now = 1000 + 60_000;
        self::assertNull($tracker->beforeSend());
        self::assertSame(0, $probes);
    }

    public function testStaleSuccessChecksOnceThenRemembersTheProbe(): void
    {
        $now = 1000;
        $probes = 0;
        $tracker = new Reachability(
            null,
            static function () use (&$now): int {
                return $now;
            },
            static function () use (&$probes): ?string {
                $probes++;

                return null;
            },
            5 * 60 * 1000,
            15_000,
        );

        $tracker->noteUp();
        $now = 1000 + 5 * 60 * 1000 + 1;
        self::assertNull($tracker->beforeSend());
        self::assertSame(1, $probes);
        self::assertNull($tracker->beforeSend());
        self::assertSame(1, $probes);
    }

    public function testTrackerSkipsWhileDown(): void
    {
        $now = 1000;
        $tracker = new Reachability(
            static function () use (&$now): int {
                return $now;
            },
            null,
            5 * 60 * 1000,
            15_000,
        );

        self::assertNull($tracker->beforeSend());
        $tracker->noteDown(Reachability::HOST_OFFLINE);

        $blocked = $tracker->beforeSend();
        self::assertNotNull($blocked);
        self::assertTrue($blocked->isErr());
        $parsed = ParseError::parse($blocked->error());
        self::assertSame('network', $parsed->category);
        self::assertSame(Reachability::CODE, $parsed->code);
        self::assertSame(Reachability::HOST_OFFLINE, $parsed->message);

        $now = 1000 + 15_001;
        self::assertNull($tracker->beforeSend());
    }

    public function testChecksBeforeNextCallWhenLastFailed(): void
    {
        $now = 1000;
        $probes = 0;
        $tracker = new Reachability(
            null,
            static function () use (&$now): int {
                return $now;
            },
            static function () use (&$probes): ?string {
                $probes++;

                return Reachability::NYLON_DOWN;
            },
            5 * 60 * 1000,
            15_000,
        );

        $tracker->noteDown(Reachability::NYLON_DOWN);
        $now = 1000 + 15_001;
        $blocked = $tracker->beforeSend();
        self::assertSame(1, $probes);
        self::assertNotNull($blocked);
        self::assertTrue($blocked->isErr());
    }

    public function testHoursOldDownIsNotTrusted(): void
    {
        $now = 1000;
        $probes = 0;
        $tracker = new Reachability(
            null,
            static function () use (&$now): int {
                return $now;
            },
            static function () use (&$probes): ?string {
                $probes++;

                return null;
            },
            5 * 60 * 1000,
            15_000,
        );

        $tracker->noteDown(Reachability::NYLON_DOWN);
        $now = 1000 + 6 * 60 * 60 * 1000;
        self::assertNull($tracker->beforeSend());
        self::assertSame(1, $probes);
    }

    public function testTransportSkipsSecondCallAfterConnectError(): void
    {
        $calls = 0;
        $reported = [];
        $client = new MockHttpClient();
        $client->setHandler(static function () use (&$calls): array {
            $calls++;
            throw new \RuntimeException('Failed to connect to host', 7);
        });

        $transport = new Transport([
            'apiKey' => 'npk_test',
            'apiSecret' => 'nps_test_secret',
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'timeoutMs' => 1000,
            'httpClient' => $client,
            'onError' => static function (object $error) use (&$reported): void {
                $reported[] = $error;
            },
        ]);

        $first = $transport->send(['action' => 'sdk-get-status', 'payload' => []]);
        self::assertTrue($first->isErr());
        $parsed = ParseError::parse($first->error());
        self::assertSame(Reachability::NYLON_DOWN, $parsed->message);
        self::assertSame(Reachability::CODE, $parsed->code);
        self::assertCount(1, $reported);
        self::assertSame(Reachability::NYLON_DOWN, $reported[0]->message);

        $second = $transport->send(['action' => 'sdk-get-status', 'payload' => []]);
        self::assertTrue($second->isErr());
        self::assertSame(1, $calls);
        self::assertCount(2, $reported);
    }
}
