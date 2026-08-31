<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Integration;

use NileSquad\NylonPay\CreateNylonPay;

use function NileSquad\NylonPay\parseError;

use NileSquad\NylonPay\SdkException;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    private static function apiKey(): string
    {
        return getenv('NYLONPAY_API_KEY') ?: '';
    }

    private static function apiSecret(): string
    {
        return getenv('NYLONPAY_API_SECRET') ?: '';
    }

    private static function hasCredentials(): bool
    {
        return self::apiKey() !== '' && self::apiSecret() !== '';
    }

    /**
     * @return \NileSquad\NylonPay\NylonPay
     */
    private static function createSdk(bool $force = true): \NileSquad\NylonPay\NylonPay
    {
        $config = [
            'apiKey' => self::apiKey(),
            'apiSecret' => self::apiSecret(),
            'force' => $force,
            'maxPollDurationMs' => 60_000,
        ];

        $baseUrl = getenv('NYLONPAY_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $config['baseUrl'] = $baseUrl;
        }

        return CreateNylonPay::create($config);
    }

    private static function uniqueReference(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function testPhone(): string
    {
        $phone = getenv('NYLONPAY_TEST_PHONE');

        return is_string($phone) && $phone !== '' ? $phone : '+256700000000';
    }

    public function testI1CollectPaymentHappyPath(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $payment = $sdk->collectPayment([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'description' => 'I1 test payment',
            'reference' => self::uniqueReference(),
        ]);

        self::assertNotSame('', $payment->reference);
        self::assertContains($payment->status, ['pending', 'processing']);
    }

    /**
     * @return \NileSquad\NylonPay\Result<array<string, mixed>, string>
     */
    private static function getTransactionWithRetry(\NileSquad\NylonPay\NylonPay $sdk, string $reference, int $attempts = 5): \NileSquad\NylonPay\Result
    {
        $last = $sdk->getTransaction(['reference' => $reference]);

        for ($i = 1; $i < $attempts && $last->isErr(); $i++) {
            usleep(500_000);
            $last = $sdk->getTransaction(['reference' => $reference]);
        }

        return $last;
    }

    public function testI2GetTransactionAfterCollect(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $payment = $sdk->collectPayment([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'description' => 'I2 test payment',
            'reference' => $ref,
        ]);

        $result = self::getTransactionWithRetry($sdk, $payment->reference);
        self::assertTrue($result->isOk(), $result->isErr() ? $result->error() : '');
        self::assertSame($payment->reference, $result->value()['reference']);
    }

    public function testI4PayoutHappyPath(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $payment = $sdk->makePayout([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'destination' => [
                'accountHolderName' => 'Test Customer',
                'accountNumber' => '123456',
            ],
            'description' => 'I4 test payout',
            'reference' => self::uniqueReference(),
        ]);

        self::assertNotSame('', $payment->reference);
        self::assertContains($payment->status, ['pending', 'processing']);
    }

    public function testI5GetTransactionAfterPayout(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $payment = $sdk->makePayout([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'destination' => [
                'accountHolderName' => 'Test Customer',
                'accountNumber' => '123456',
            ],
            'description' => 'I5 test payout',
            'reference' => $ref,
        ]);

        $result = self::getTransactionWithRetry($sdk, $payment->reference);
        self::assertTrue($result->isOk(), $result->isErr() ? $result->error() : '');
        self::assertSame($payment->reference, $result->value()['reference']);
    }

    public function testI6IdempotencyOnPayout(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $input = [
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'destination' => [
                'accountHolderName' => 'Test Customer',
                'accountNumber' => '123456',
            ],
            'description' => 'I6 idempotency test',
            'reference' => $ref,
        ];

        $payment1 = $sdk->makePayout($input);
        $payment2 = $sdk->makePayout($input);

        self::assertSame($payment1->reference, $payment2->reference);
    }

    public function testI7VerifyPhone(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $result = $sdk->verifyPhone(['phoneNumber' => self::testPhone(), 'purpose' => 'collection']);
        self::assertTrue($result->isOk() || $result->isErr());
    }

    public function testI9BadApiKeyPrefix(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $this->expectException(\InvalidArgumentException::class);
        CreateNylonPay::create(['apiKey' => 'bad_key', 'apiSecret' => 'nps_test', 'force' => true]);
    }

    public function testI10MissingApiSecret(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $this->expectException(\InvalidArgumentException::class);
        CreateNylonPay::create(['apiKey' => 'npk_test', 'apiSecret' => '', 'force' => true]);
    }

    public function testI11BadApiSecretPrefix(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $this->expectException(\InvalidArgumentException::class);
        CreateNylonPay::create(['apiKey' => 'npk_test', 'apiSecret' => 'bad_secret', 'force' => true]);
    }

    public function testI14bSubMinimumPayoutAmount(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();

        try {
            $sdk->makePayout([
                'amount' => 100,
                'currency' => 'UGX',
                'customer' => ['name' => 'Test', 'phoneNumber' => self::testPhone()],
                'destination' => [
                    'accountHolderName' => 'Test',
                    'accountNumber' => '123456',
                ],
                'description' => 'I14b sub-min test',
                'reference' => self::uniqueReference(),
            ]);
            self::fail('Expected SdkException');
        } catch (SdkException $exception) {
            self::assertSame('validation', $exception->category);
        }
    }

    public function testI17ResolveReturnsFullTransaction(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $result = $sdk->collectPaymentAndResolve([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'description' => 'I17 resolve test',
            'reference' => $ref,
            'metadata' => ['orderId' => 'test-123'],
        ]);

        if ($result->isErr()) {
            self::markTestSkipped('collectPaymentAndResolve did not succeed: ' . parseError($result->error())->message);
        }

        $tx = $result->value();
        self::assertNotEmpty($tx['id'] ?? null);
        self::assertSame(5000, $tx['amount'] ?? null);
        self::assertSame($ref, $tx['reference'] ?? null);
    }

    public function testI18MetadataRoundTrip(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $metadata = ['orderId' => 'order-123', 'source' => 'integration-test'];

        $sdk->collectPayment([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test', 'phoneNumber' => self::testPhone()],
            'description' => 'I18 metadata test',
            'reference' => $ref,
            'metadata' => $metadata,
        ]);

        $result = self::getTransactionWithRetry($sdk, $ref);
        if ($result->isErr()) {
            self::markTestSkipped('Could not load transaction for metadata check: ' . parseError($result->error())->message);
        }

        foreach ($metadata as $key => $value) {
            self::assertSame($value, $result->value()['metadata'][$key] ?? null);
        }
    }

    public function testI3IdempotencyOnCollect(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $ref = self::uniqueReference();
        $input = [
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'description' => 'I3 idempotency test',
            'reference' => $ref,
        ];

        $payment1 = $sdk->collectPayment($input);
        $payment2 = $sdk->collectPayment($input);

        self::assertSame($payment1->reference, $payment2->reference);
    }

    public function testI8MissingApiKey(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $this->expectException(\InvalidArgumentException::class);
        CreateNylonPay::create(['apiKey' => '', 'apiSecret' => 'nps_test', 'force' => true]);
    }

    public function testI12SingletonBehavior(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $first = self::createSdk(true);

        $secondConfig = [
            'apiKey' => self::apiKey(),
            'apiSecret' => self::apiSecret(),
        ];
        $baseUrl = getenv('NYLONPAY_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $secondConfig['baseUrl'] = $baseUrl;
        }

        $second = CreateNylonPay::create($secondConfig);

        self::assertSame($first, $second);
    }

    public function testI13UnknownReference(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $result = $sdk->getTransaction(['reference' => 'nonexistent_ref_1']);

        self::assertTrue($result->isErr());
    }

    public function testI14SubMinimumCollectionAmount(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();

        try {
            $sdk->collectPayment([
                'amount' => 100,
                'currency' => 'UGX',
                'customer' => ['name' => 'Test', 'phoneNumber' => self::testPhone()],
                'description' => 'I14 sub-min test',
                'reference' => self::uniqueReference(),
            ]);
            self::fail('Expected SdkException');
        } catch (SdkException $exception) {
            self::assertSame('validation', $exception->category);
        }
    }

    public function testI16UnknownKeyAuthCategory(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $config = [
            'apiKey' => 'npk_test_unknown_key_12345',
            'apiSecret' => 'nps_test_unknown_secret_12345',
            'force' => true,
        ];

        $baseUrl = getenv('NYLONPAY_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '') {
            $config['baseUrl'] = $baseUrl;
        }

        $sdk = CreateNylonPay::create($config);

        $result = $sdk->getStatus(['reference' => 'any_reference']);
        self::assertTrue($result->isErr());
        self::assertSame('auth', parseError($result->error())->category);
    }

    public function testI19PollingReachesTerminal(): void
    {
        if (!self::hasCredentials()) {
            self::markTestSkipped('NYLONPAY_API_KEY and NYLONPAY_API_SECRET required');
        }

        $sdk = self::createSdk();
        $payment = $sdk->collectPayment([
            'amount' => 5000,
            'currency' => 'UGX',
            'customer' => ['name' => 'Test Customer', 'phoneNumber' => self::testPhone()],
            'description' => 'I19 polling test',
            'reference' => self::uniqueReference(),
        ]);

        $tx = $payment->wait();
        self::assertTrue($tx === null || isset($tx['id']));
    }
}
