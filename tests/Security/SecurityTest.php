<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Security;

use NileSquad\NylonPay\Config;
use NileSquad\NylonPay\CreateNylonPay;
use NileSquad\NylonPay\Fingerprint;
use NileSquad\NylonPay\Nonce;
use NileSquad\NylonPay\ParseError;
use NileSquad\NylonPay\Signature;
use NileSquad\NylonPay\Tests\Support\MockHttpClient;
use NileSquad\NylonPay\Transport;
use NileSquad\NylonPay\VerifyResponse;
use NileSquad\NylonPay\VerifyWebhook;

use function NileSquad\NylonPay\verifyWebhookSignature;

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private const SECRET = 'nps_test_security_secret_xyz';

    private function sign(mixed $data, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', Signature::createCanonicalPayload($data), $secret);
    }

    public function testS1SignatureDeterministic(): void
    {
        $args = [
            'fingerprint' => 'fp_1',
            'nonce' => 'nonce_1',
            'timestamp' => '1700000000000',
            'payload' => ['amount' => 1000, 'currency' => 'UGX'],
            'secret' => self::SECRET,
        ];

        $sigA = Signature::createSignature($args);
        $sigB = Signature::createSignature($args);

        self::assertSame($sigA, $sigB);
        self::assertSame(64, strlen($sigA));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sigA);
    }

    public function testS1SignatureChangesWithPayload(): void
    {
        $base = [
            'fingerprint' => 'fp',
            'nonce' => 'n',
            'timestamp' => 't',
            'secret' => self::SECRET,
        ];

        $a = Signature::createSignature([...$base, 'payload' => ['x' => 1]]);
        $b = Signature::createSignature([...$base, 'payload' => ['x' => 2]]);

        self::assertNotSame($a, $b);
    }

    public function testS2TopLevelKeyOrderIndependent(): void
    {
        $a = Signature::createCanonicalPayload(['b' => 1, 'a' => 2, 'c' => 3]);
        $b = Signature::createCanonicalPayload(['c' => 3, 'a' => 2, 'b' => 1]);

        self::assertSame($a, $b);
        self::assertSame('{"a":2,"b":1,"c":3}', $a);
    }

    public function testS3DefaultLength32Hex(): void
    {
        $nonce = Nonce::generate();
        self::assertSame(32, strlen($nonce));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    public function testS4FingerprintStable64Hex(): void
    {
        $a = Fingerprint::generate();
        $b = Fingerprint::generate();

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
    }

    public function testS5ResponseVerificationAcceptsValid(): void
    {
        $data = ['transaction' => ['id' => 't1', 'amount' => 1000]];
        $sig = $this->sign($data);

        self::assertTrue(VerifyResponse::verify($data, $sig, self::SECRET));
    }

    public function testS6TamperedDataRejected(): void
    {
        $data = ['x' => 1];
        $sig = $this->sign($data);

        self::assertFalse(VerifyResponse::verify(['x' => 2], $sig, self::SECRET));
    }

    public function testS7EmptySignatureNoThrow(): void
    {
        self::assertFalse(VerifyResponse::verify(['a' => 1], '', self::SECRET));
    }

    public function testS8ValidWebhookAccepted(): void
    {
        $body = ['timestamp' => (string) (int) floor(microtime(true) * 1000), 'data' => 'x'];
        $bodyBytes = json_encode($body, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $bodyBytes, self::SECRET);

        self::assertTrue(verifyWebhookSignature([
            'payload' => $bodyBytes,
            'signature' => $sig,
            'secret' => self::SECRET,
            'toleranceSeconds' => VerifyWebhook::DISABLE_FRESHNESS_CHECK,
        ]));
    }

    public function testS10MissingSignatureFailsClosed(): void
    {
        $client = new MockHttpClient();
        $client->setHandler(static fn (): array => [
            'statusCode' => 200,
            'body' => json_encode(['status' => true, 'message' => 'ok', 'data' => ['foo' => 'bar']], JSON_THROW_ON_ERROR),
        ]);

        $transport = new Transport([
            'apiKey' => 'npk_test_s10',
            'apiSecret' => self::SECRET,
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'timeoutMs' => 1000,
            'httpClient' => $client,
        ]);

        $result = $transport->send(['action' => 'x', 'payload' => []]);
        self::assertTrue($result->isErr());
        self::assertSame('internal', ParseError::parse($result->error())->category);
    }

    public function testS11ValidSignatureAccepted(): void
    {
        $data = ['foo' => 'bar'];
        $client = new MockHttpClient();
        $client->setHandler(static function (string $url, string $body, array $headers): array {
            $payload = ['foo' => 'bar'];
            $nonce = $headers['x-nylon-nonce'] ?? '';
            $bound = [...$payload, '_requestNonce' => $nonce];
            $sig = hash_hmac('sha256', Signature::createCanonicalPayload($bound), 'nps_test_security_secret_xyz');

            return [
                'statusCode' => 200,
                'body' => json_encode([
                    'status' => true,
                    'message' => 'ok',
                    'data' => [...$bound, '_responseSignature' => $sig],
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $transport = new Transport([
            'apiKey' => 'npk_test_s11b',
            'apiSecret' => self::SECRET,
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'httpClient' => $client,
        ]);

        $result = $transport->send(['action' => 'x', 'payload' => []]);
        self::assertTrue($result->isOk());
        self::assertSame($data, $result->value());
    }

    public function testS12BadApiKeyPrefixRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey must start with "npk_"');

        CreateNylonPay::create(['apiKey' => 'bad_key', 'apiSecret' => 'nps_test_abc', 'force' => true]);
    }

    public function testS13DifferentSecretYieldsDifferentInstance(): void
    {
        $a = CreateNylonPay::create([
            'apiKey' => 'npk_test_s13',
            'apiSecret' => 'nps_test_secret_alpha',
            'force' => true,
        ]);
        $b = CreateNylonPay::create([
            'apiKey' => 'npk_test_s13',
            'apiSecret' => 'nps_test_secret_beta',
            'force' => true,
        ]);

        self::assertNotSame($a, $b);
    }

    public function testS14StaleTimestampRejected(): void
    {
        $staleMs = (int) floor(microtime(true) * 1000) - 600_000;
        $body = ['timestamp' => (string) $staleMs, 'data' => 'x'];
        $bodyBytes = json_encode($body, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $bodyBytes, self::SECRET);

        self::assertFalse(verifyWebhookSignature([
            'payload' => $bodyBytes,
            'signature' => $sig,
            'secret' => self::SECRET,
        ]));
    }

    public function testS15RejectsReplayedResponse(): void
    {
        $data = ['reference' => 'r', 'status' => 'successful'];
        $captured = null;
        $client = new MockHttpClient();
        $client->setHandler(static function (string $url, string $body, array $headers) use (&$captured): array {
            if ($captured === null) {
                $bound = [
                    'reference' => 'r',
                    'status' => 'successful',
                    '_requestNonce' => $headers['x-nylon-nonce'] ?? '',
                ];
                $captured = [...$bound, '_responseSignature' => hash_hmac(
                    'sha256',
                    Signature::createCanonicalPayload($bound),
                    'nps_test_security_secret_xyz',
                )];
            }

            return [
                'statusCode' => 200,
                'body' => json_encode(['status' => true, 'message' => 'ok', 'data' => $captured], JSON_THROW_ON_ERROR),
            ];
        });

        $transport = new Transport([
            'apiKey' => 'npk_test_s15',
            'apiSecret' => self::SECRET,
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'httpClient' => $client,
        ]);

        $first = $transport->send(['action' => 'sdk-get-status', 'payload' => []]);
        $replayed = $transport->send(['action' => 'sdk-get-status', 'payload' => []]);

        self::assertTrue($first->isOk());
        self::assertTrue($replayed->isErr());
    }

    public function testS16UppercaseSignatureRejected(): void
    {
        $body = ['timestamp' => (string) (int) floor(microtime(true) * 1000), 'data' => 'x'];
        $bodyBytes = json_encode($body, JSON_THROW_ON_ERROR);
        $sig = strtoupper(hash_hmac('sha256', $bodyBytes, self::SECRET));

        self::assertFalse(verifyWebhookSignature([
            'payload' => $bodyBytes,
            'signature' => $sig,
            'secret' => self::SECRET,
            'toleranceSeconds' => VerifyWebhook::DISABLE_FRESHNESS_CHECK,
        ]));
    }

    public function testS17OversizedDeclaredLengthRejected(): void
    {
        $client = new MockHttpClient();
        $client->setHandler(static fn (): array => [
            'statusCode' => 200,
            'body' => '',
            'headers' => ['content-length' => (string) (Config::MAX_RESPONSE_BYTES + 1)],
        ]);

        $transport = new Transport([
            'apiKey' => 'npk_test_s17',
            'apiSecret' => self::SECRET,
            'baseUrl' => 'https://api.test/services',
            'maxRetries' => 0,
            'httpClient' => $client,
        ]);

        $result = $transport->send(['action' => 'x', 'payload' => []]);
        self::assertTrue($result->isErr());
    }

    public function testS18ZeroToleranceIsStrict(): void
    {
        $staleMs = (int) floor(microtime(true) * 1000) - 1000;
        $body = ['timestamp' => (string) $staleMs, 'data' => 'x'];
        $bodyBytes = json_encode($body, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $bodyBytes, self::SECRET);

        self::assertFalse(verifyWebhookSignature([
            'payload' => $bodyBytes,
            'signature' => $sig,
            'secret' => self::SECRET,
            'toleranceSeconds' => 0,
        ]));
    }
}
