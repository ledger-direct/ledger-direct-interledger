<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Interledger\Credentials;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private const PEM = "-----BEGIN PRIVATE KEY-----\nTk9UQVJFQUxLRVk=\n-----END PRIVATE KEY-----\n";

    public function testTheKeyIsTheBase64OfItsPem(): void
    {
        $credentials = Credentials::fromBase64Pem('4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77', base64_encode(self::PEM));

        self::assertSame('4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77', $credentials->keyId);
        self::assertSame(base64_encode(self::PEM), $credentials->privateKey);
    }

    public function testThePemItselfIsRefusedWithAnInstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/base64/');

        Credentials::fromBase64Pem('key-id', self::PEM);
    }

    public function testSomethingThatIsNotAKeyAtAllIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Credentials::fromBase64Pem('key-id', base64_encode('hello'));
    }

    public function testAMissingKeyIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Credentials::fromBase64Pem('  ', base64_encode(self::PEM));
    }

    public function testTheKeyIsNeverInADump(): void
    {
        $credentials = Credentials::fromBase64Pem('key-id', base64_encode(self::PEM));

        self::assertSame(['keyId' => 'key-id', 'privateKey' => '[redacted]', 'keyOnly' => false], $credentials->__debugInfo());
        self::assertStringNotContainsString(base64_encode(self::PEM), print_r($credentials, true));
    }

    /**
     * A client known by its key alone: nothing is registered at a wallet, so the
     * key id is the key's own thumbprint and the public key travels as a JWK.
     * The key is the one of RFC 8037, whose public key and thumbprint are published.
     */
    public function testAKeyOnlyClientIsNamedByItsKeysThumbprint(): void
    {
        $credentials = Credentials::keyOnly(TestKey::base64Pem());

        self::assertTrue($credentials->isKeyOnly());
        self::assertSame(TestKey::THUMBPRINT, $credentials->keyId);
        self::assertSame([
            'kid' => TestKey::THUMBPRINT,
            'alg' => 'EdDSA',
            'use' => 'sig',
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => TestKey::PUBLIC_BASE64URL,
        ], $credentials->publicJwk());
    }

    public function testTheJwkNeverCarriesThePrivateKey(): void
    {
        $jwk = Credentials::keyOnly(TestKey::base64Pem())->publicJwk();

        self::assertArrayNotHasKey('d', $jwk);
        self::assertStringNotContainsString(TestKey::SEED_BASE64URL, json_encode($jwk, JSON_THROW_ON_ERROR));
    }

    public function testARegisteredKeyIsNotKeyOnlyAndKeepsItsConfiguredId(): void
    {
        $credentials = Credentials::fromBase64Pem('4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77', TestKey::base64Pem());

        self::assertFalse($credentials->isKeyOnly());
        self::assertSame('4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77', $credentials->publicJwk()['kid']);
        self::assertSame(TestKey::PUBLIC_BASE64URL, $credentials->publicJwk()['x']);
    }

    /**
     * The thumbprint is computed from the key, so here a value that only looks
     * like a key is refused at once - where a registered key id would have let
     * it through to fail at the first signature.
     */
    public function testAKeyOnlyClientNeedsARealEd25519Key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Ed25519/');

        Credentials::keyOnly(base64_encode(self::PEM));
    }

    public function testAKeyOnlyClientRefusesThePemItselfWithTheSameInstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/base64/');

        Credentials::keyOnly((string) base64_decode(TestKey::base64Pem(), true));
    }

    public function testAKeyOnlyClientsKeyIsNotInADumpEither(): void
    {
        $credentials = Credentials::keyOnly(TestKey::base64Pem());

        self::assertSame(['keyId' => TestKey::THUMBPRINT, 'privateKey' => '[redacted]', 'keyOnly' => true], $credentials->__debugInfo());
        self::assertStringNotContainsString(TestKey::base64Pem(), print_r($credentials, true));
    }
}
