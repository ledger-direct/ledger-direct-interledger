<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests\Client\Sdk;

use Hardcastle\LedgerDirect\Interledger\Client\Sdk\SignedApiClient;
use Hardcastle\LedgerDirect\Interledger\Tests\TestKey;
use PHPUnit\Framework\TestCase;

/**
 * RFC 9421 carries a signature as an RFC 8941 byte sequence, `sig1=:…:`. The
 * SDK leaves the colons out; a Dassie node answers that with 401 "Malformed".
 */
final class SignedApiClientTest extends TestCase
{
    public function testTheSignatureHeaderIsAByteSequenceOverTheSdksOwnSignature(): void
    {
        $body = '{"access_token":{"access":[{"type":"incoming-payment","actions":["create"]}]}}';
        $headers = (new SignedApiClient(TestKey::base64Pem(), 'key-1'))->generateHttpSignature(
            'POST',
            'https://node.example/gnap',
            $body,
            ['Content-Type' => 'application/json', 'Content-Length' => strlen($body)],
        );
        $headers = array_change_key_case($headers, CASE_LOWER);

        self::assertMatchesRegularExpression('/^sig1=:[A-Za-z0-9+\/]{86}==:$/', $headers['signature']);
        self::assertSame(64, strlen((string) base64_decode(substr($headers['signature'], 6, -1), true)), 'an Ed25519 signature');
        self::assertStringStartsWith('sig1=("@method" "@target-uri" "content-digest"', $headers['signature-input']);
        self::assertStringContainsString('keyid="key-1"', $headers['signature-input']);
    }

    public function testTheSignatureVerifiesUnderThePublicKey(): void
    {
        $headers = array_change_key_case(
            (new SignedApiClient(TestKey::base64Pem(), 'key-1'))->generateHttpSignature('POST', 'https://node.example/gnap', '{}', [
                'Content-Type' => 'application/json',
                'Content-Length' => 2,
            ]),
            CASE_LOWER,
        );

        // The signature base of RFC 9421 for the components the SDK covers, rebuilt here.
        $base = '"@method": POST' . "\n"
            . '"@target-uri": https://node.example/gnap' . "\n"
            . '"content-digest": ' . $headers['content-digest'] . "\n"
            . '"content-length": 2' . "\n"
            . '"content-type": application/json' . "\n"
            . '"@signature-params": ' . substr($headers['signature-input'], strlen('sig1='));

        self::assertTrue(sodium_crypto_sign_verify_detached(
            (string) base64_decode(substr($headers['signature'], 6, -1), true),
            $base,
            TestKey::publicKey(),
        ), 'only the framing changed, not what was signed');
    }

    public function testOnlyTheSdksBareFormIsRewritten(): void
    {
        self::assertSame('sig1=:AAAA:', SignedApiClient::asByteSequence('sig1=AAAA'));
        self::assertSame('sig1=:AA+/AA==:', SignedApiClient::asByteSequence('sig1=AA+/AA=='));
        self::assertSame('sig1=:AAAA:', SignedApiClient::asByteSequence('sig1=:AAAA:'), 'already a byte sequence');
        self::assertSame('sig1=:AAAA:, sig2=:BBBB:', SignedApiClient::asByteSequence('sig1=:AAAA:, sig2=:BBBB:'));
        self::assertSame('not a signature', SignedApiClient::asByteSequence('not a signature'));
    }
}
