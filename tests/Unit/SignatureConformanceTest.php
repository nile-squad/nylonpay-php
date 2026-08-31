<?php

declare(strict_types=1);

namespace NileSquad\NylonPay\Tests\Unit;

use NileSquad\NylonPay\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Signing conformance, the spec's canonical vectors V1-V7 (requirement S19).
 *
 * These are NOT self-generated. They are the conformance vectors published in
 * the Nylon Pay SDK Spec (transport.md, "Conformance vectors"), generated from
 * the reference implementation and verified against the backend's own
 * verifySignature. Reproducing them proves this SDK agrees with the backend,
 * not merely with itself.
 *
 * Payloads are held as verbatim JSON text from the spec and decoded at test
 * time, so there is no transcription drift between the spec and this file.
 * Decoding keeps objects as stdClass so an empty JSON object stays `{}` and
 * does not collapse into PHP's ambiguous `[]`.
 */
final class SignatureConformanceTest extends TestCase
{
    private const SECRET = 'nps_test_conformance_secret';
    private const NONCE = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
    private const TIMESTAMP = '1718976000000';

    private static function fingerprint(): string
    {
        return str_repeat('a', 64);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function vectors(): array
    {
        return [
            'V1 representative payload' => [
                '{"amount":5000,"currency":"UGX","customer":{"name":"John Doe","phoneNumber":"+256700000000"},"description":"Test payment","reference":"ORDER-2026-001","metadata":{"orderId":"12345","items":"3"}}',
                '{"amount":5000,"currency":"UGX","customer":{"name":"John Doe","phoneNumber":"+256700000000"},"description":"Test payment","metadata":{"items":"3","orderId":"12345"},"reference":"ORDER-2026-001"}',
                'dc6e1717d7c37d7a3b334087d9882c07663edb2dfc8f2f06cd77c0d2d8a58686',
            ],
            'V2 key insertion order is irrelevant' => [
                '{"metadata":{"items":"3","orderId":"12345"},"reference":"ORDER-2026-001","description":"Test payment","customer":{"phoneNumber":"+256700000000","name":"John Doe"},"currency":"UGX","amount":5000}',
                '{"amount":5000,"currency":"UGX","customer":{"name":"John Doe","phoneNumber":"+256700000000"},"description":"Test payment","metadata":{"items":"3","orderId":"12345"},"reference":"ORDER-2026-001"}',
                'dc6e1717d7c37d7a3b334087d9882c07663edb2dfc8f2f06cd77c0d2d8a58686',
            ],
            'V3 arrays keep order, nested objects sorted' => [
                '{"items":[{"unitPrice":2000,"name":"Zeta","quantity":1},{"name":"Alpha","quantity":2,"unitPrice":500}],"tags":["b","a","c"],"amount":4500}',
                '{"amount":4500,"items":[{"name":"Zeta","quantity":1,"unitPrice":2000},{"name":"Alpha","quantity":2,"unitPrice":500}],"tags":["b","a","c"]}',
                '98478585cf5ce0193a9aa6a6e86ff7f5dfc025945d03547dd548b9356b797e4b',
            ],
            'V4 string escaping' => [
                '{"note":"café / 50% <b>&\"quoted\"</b>","path":"a/b/c","backslash":"x\\\\y","newline":"line1\nline2\ttab"}',
                '{"backslash":"x\\\\y","newline":"line1\nline2\ttab","note":"café / 50% <b>&\"quoted\"</b>","path":"a/b/c"}',
                '80eb3c6e35b8b3dcc67a57e056634b6f68f2f84b9454bea3aa5e86647eb47649',
            ],
            'V5 ASCII key ordering' => [
                '{"Z":1,"_x":2,"a":3,"A":4,"z":5,"0":6}',
                '{"0":6,"A":4,"Z":1,"_x":2,"a":3,"z":5}',
                '7b9da2fccf0140a7b721715b7b61f17ad3407bd40659b54d983c8e8379108adc',
            ],
            'V6 empty containers and zero' => [
                '{"emptyObject":{},"emptyArray":[],"emptyString":"","zero":0}',
                '{"emptyArray":[],"emptyObject":{},"emptyString":"","zero":0}',
                'f1d8a628663cc9279c675b001e5142e10c6880c2713145f7ebb946c73af2e875',
            ],
            'V7 non-ASCII key ordering' => [
                '{"ÿ":1,"Ā":2,"a":3,"注文":4}',
                '{"a":3,"ÿ":1,"Ā":2,"注文":4}',
                'f43182515649622666b920ac1274d6be5ee395d7c295a4eab6e914a48b212a3a',
            ],
        ];
    }

    #[DataProvider('vectors')]
    public function testCanonicalPayloadMatchesSpecVector(string $payloadJson, string $expectedCanonical, string $expectedSignature): void
    {
        $payload = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expectedCanonical, Signature::createCanonicalPayload($payload));
    }

    #[DataProvider('vectors')]
    public function testSignatureMatchesSpecVector(string $payloadJson, string $expectedCanonical, string $expectedSignature): void
    {
        $payload = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);

        $signature = Signature::createSignature([
            'fingerprint' => self::fingerprint(),
            'nonce' => self::NONCE,
            'timestamp' => self::TIMESTAMP,
            'payload' => $payload,
            'secret' => self::SECRET,
        ]);

        self::assertSame($expectedSignature, $signature);
    }

    /**
     * V7 pins code-unit ordering against the UTF-16LE byte-sort mistake.
     *
     * Sorting UTF-16LE bytes compares the low byte first, which is not
     * code-unit order. This asserts the wrong ordering is genuinely different,
     * so the V7 vector cannot pass by coincidence if someone reintroduces it.
     */
    public function testV7RejectsUtf16LittleEndianOrdering(): void
    {
        $keys = ['ÿ', 'Ā', 'a', '注文'];

        $bigEndian = $keys;
        usort($bigEndian, Signature::compareKeys(...));

        $littleEndian = $keys;
        usort($littleEndian, static fn (string $first, string $second): int => mb_convert_encoding($first, 'UTF-16LE', 'UTF-8') <=> mb_convert_encoding($second, 'UTF-16LE', 'UTF-8'));

        self::assertSame(['a', 'ÿ', 'Ā', '注文'], $bigEndian);
        self::assertNotSame($bigEndian, $littleEndian);
    }

    public function testSignatureIsCanonicalLowercaseHex(): void
    {
        $signature = Signature::createSignature([
            'fingerprint' => self::fingerprint(),
            'nonce' => self::NONCE,
            'timestamp' => self::TIMESTAMP,
            'payload' => ['amount' => 5000],
            'secret' => self::SECRET,
        ]);

        self::assertSame(64, strlen($signature));
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $signature));
    }
}
