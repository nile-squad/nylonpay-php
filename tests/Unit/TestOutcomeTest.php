<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\CreateNylonPay;
use NileSquad\NylonPay\NylonPay;
use NileSquad\NylonPay\SdkException;
use NileSquad\NylonPay\Signature;
use NileSquad\NylonPay\Tests\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Sandbox-only `testOutcome` prop: forces a sandbox transaction to succeed
 * or fail so merchants can walk both paths on demand. Omitted = random.
 */
final class TestOutcomeTest extends TestCase
{
    private const SECRET = 'nps_test_outcome_secret_xyz';

    /** @var array<int, array{url: string, body: string}> */
    private array $requests = [];

    private function sdkWithCapture(): NylonPay
    {
        $client = new MockHttpClient();
        $client->setHandler(
            function (string $url, string $body, array $headers): array {
                $this->requests[] = ['url' => $url, 'body' => $body];
                $decoded = json_decode($body, true);
                $reference = is_array($decoded) ? ($decoded['payload']['reference'] ?? 'ref-fallback') : 'ref-fallback';
                $nonce = $headers['x-nylon-nonce'] ?? '';
                $bound = ['reference' => $reference, 'status' => 'pending', '_requestNonce' => $nonce];
                $sig = hash_hmac('sha256', Signature::createCanonicalPayload($bound), self::SECRET);

                return [
                    'statusCode' => 200,
                    'body' => json_encode([
                        'status' => true,
                        'message' => 'ok',
                        'data' => [...$bound, '_responseSignature' => $sig],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        );

        return CreateNylonPay::create([
            'apiKey' => 'npk_test_outcome',
            'apiSecret' => self::SECRET,
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'httpClient' => $client,
            'force' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function lastWirePayload(): array
    {
        $decoded = json_decode($this->requests[count($this->requests) - 1]['body'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('payload', $decoded);

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function collectBase(): array
    {
        return [
            'amount' => 1000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Alice', 'phoneNumber' => '+256700000000'],
            'description' => 'Order #1',
        ];
    }

    /** @return array<string, mixed> */
    private function payoutBase(): array
    {
        return [
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Alice', 'phoneNumber' => '+256700000000'],
            'destination' => ['accountHolderName' => 'Alice', 'accountNumber' => '123456'],
            'description' => 'Payout #1',
        ];
    }

    public function testCollectPaymentForwardsTestOutcome(): void
    {
        $sdk = $this->sdkWithCapture();
        $sdk->collectPayment([...$this->collectBase(), 'testOutcome' => 'fail']);

        self::assertSame('fail', $this->lastWirePayload()['testOutcome'] ?? null);
    }

    public function testMakePayoutForwardsTestOutcome(): void
    {
        $sdk = $this->sdkWithCapture();
        $sdk->makePayout([...$this->payoutBase(), 'testOutcome' => 'success']);

        self::assertSame('success', $this->lastWirePayload()['testOutcome'] ?? null);
    }

    public function testOmittedTestOutcomeIsAbsentFromWire(): void
    {
        $sdk = $this->sdkWithCapture();
        $sdk->collectPayment($this->collectBase());

        self::assertArrayNotHasKey('testOutcome', $this->lastWirePayload());
    }

    public function testInvalidTestOutcomeThrowsBeforeNetwork(): void
    {
        $sdk = $this->sdkWithCapture();

        try {
            $sdk->collectPayment([...$this->collectBase(), 'testOutcome' => 'sometimes']);
            self::fail('Expected SdkException for invalid testOutcome');
        } catch (SdkException $e) {
            self::assertSame('validation', $e->category);
            self::assertStringContainsString('testOutcome must be "success" or "fail"', $e->getMessage());
        }

        try {
            $sdk->makePayout([...$this->payoutBase(), 'testOutcome' => 'sometimes']);
            self::fail('Expected SdkException for invalid testOutcome');
        } catch (SdkException $e) {
            self::assertSame('validation', $e->category);
        }

        self::assertCount(0, $this->requests);
    }
}
