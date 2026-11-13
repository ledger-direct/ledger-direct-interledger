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

        self::assertSame(['keyId' => 'key-id', 'privateKey' => '[redacted]'], $credentials->__debugInfo());
        self::assertStringNotContainsString(base64_encode(self::PEM), print_r($credentials, true));
    }
}
