<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests\Client;

use Hardcastle\LedgerDirect\Interledger\Client\WalletAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The wallet address document decides where every later request goes and what
 * scale every amount is in, so what it says is checked before it is believed.
 */
final class WalletAddressTest extends TestCase
{
    private const DOCUMENT = [
        'id' => 'https://ilp.example.test/merchant',
        'publicName' => 'Acme',
        'assetCode' => 'EUR',
        'assetScale' => 2,
        'authServer' => 'https://auth.example.test/0102',
        'resourceServer' => 'https://ilp.example.test/0102',
    ];

    public function testADocumentIsReadAsItIs(): void
    {
        $wallet = WalletAddress::fromArray(self::DOCUMENT);

        self::assertSame('https://ilp.example.test/merchant', $wallet->id);
        self::assertSame('EUR', $wallet->assetCode);
        self::assertSame(2, $wallet->assetScale);
        self::assertSame('https://auth.example.test/0102', $wallet->authServer);
        self::assertSame('https://ilp.example.test/0102', $wallet->resourceServer);
        self::assertSame('Acme', $wallet->publicName);
    }

    public function testAMissingAssetScaleIsRefusedRatherThanReadAsZero(): void
    {
        $document = self::DOCUMENT;
        unset($document['assetScale']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/assetScale/');

        WalletAddress::fromArray($document);
    }

    public function testAZeroScaleIsStillAllowed(): void
    {
        self::assertSame(0, WalletAddress::fromArray(['assetScale' => 0] + self::DOCUMENT)->assetScale);
    }

    /**
     * Every later request goes to these servers with the merchant's signature
     * or an access token, so a document that names a plain-http one is not
     * followed.
     */
    public function testAServerThatIsNotHttpsIsRefused(): void
    {
        foreach (['id', 'authServer', 'resourceServer'] as $field) {
            $document = self::DOCUMENT;
            $document[$field] = 'http://ilp.example.test/plain';

            try {
                WalletAddress::fromArray($document);
                self::fail("{$field} was accepted over plain http");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function testAMissingFieldIsNamed(): void
    {
        $document = self::DOCUMENT;
        $document['assetCode'] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/assetCode/');

        WalletAddress::fromArray($document);
    }
}
