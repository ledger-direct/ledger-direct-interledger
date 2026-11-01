<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Interledger\Chain;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChainTest extends TestCase
{
    public function testTheTypeNamesTheAssetAndReadsBack(): void
    {
        self::assertSame('interledger-eur-payment', Chain::typeFor('EUR'));
        self::assertSame('interledger-dsusd-payment', Chain::typeFor('DSUSD'));
        self::assertSame('EUR', Chain::assetForType('interledger-eur-payment'));
        self::assertNull(Chain::assetForType('stellar-usdc-payment'));
        self::assertNull(Chain::assetForType('interledger--payment'));
    }

    public function testAnUnusableAssetCodeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Chain::typeFor('EUR/USD');
    }

    public function testAPaymentPointerBecomesItsUrlForm(): void
    {
        self::assertSame(
            'https://wallet.example.dev/alice',
            Chain::normalizeWalletAddress('$wallet.example.dev/alice'),
        );
    }

    public function testNormalisingLowercasesTheHostAndDropsATrailingSlash(): void
    {
        self::assertSame(
            'https://ilp.example.dev/Alice',
            Chain::normalizeWalletAddress('  https://ILP.example.dev/Alice/  '),
            'the path keeps its case: a wallet address path is case-sensitive',
        );
    }

    public function testOnlyAnHttpsUrlIsAWalletAddress(): void
    {
        self::assertTrue(Chain::isWalletAddress('https://ilp.interledger-test.dev/ld-merchant'));
        self::assertTrue(Chain::isWalletAddress('https://localhost:10100/pay/xrp'));
        self::assertFalse(Chain::isWalletAddress('http://ilp.example.dev/alice'));
        self::assertFalse(Chain::isWalletAddress('$ilp.example.dev/alice'));
        self::assertFalse(Chain::isWalletAddress('rN7n7otQDd6FczFgLdSqtcsAUxDkw6fzRH'));
        self::assertFalse(Chain::isWalletAddress(''));
    }

    public function testThePaymentIdIsTheLastSegmentOfTheUrl(): void
    {
        self::assertSame(
            'cc294351-d4e2-411e-ae25-14b5118c8776',
            Chain::paymentIdFrom('https://ilp.test/0102/incoming-payments/cc294351-d4e2-411e-ae25-14b5118c8776'),
        );
    }

    public function testAUrlWithoutAnIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Chain::paymentIdFrom('https://ilp.test/');
    }

    public function testTheDescriptorHasNoNativeAssetAndEveryAssetsOwnType(): void
    {
        $descriptor = Chain::descriptor();

        self::assertSame('INTERLEDGER', $descriptor->chain);
        self::assertSame('', $descriptor->nativeAsset, 'an Open Payments balance is always issued');
        self::assertSame(['mainnet', 'testnet'], $descriptor->networks);
        self::assertSame(['EUR', 'USD', 'XRP', 'DSUSD'], $descriptor->assetCodes());

        foreach ($descriptor->assets as $asset) {
            self::assertSame(Chain::typeFor($asset->code), $asset->type);
            self::assertFalse($asset->native, "{$asset->code} must not be a native asset");
        }

        self::assertSame('EUR', $descriptor->asset('EUR')->pegCurrency);
        self::assertSame('USD', $descriptor->asset('DSUSD')->pegCurrency);
        self::assertNull($descriptor->asset('XRP')->pegCurrency);
        self::assertSame([], $descriptor->asset('EUR')->issuers, 'the issuer is the merchant\'s own wallet');
    }

    public function testThePegIsReadFromTheDescriptor(): void
    {
        self::assertSame('EUR', Chain::pegCurrencyFor('EUR'));
        self::assertSame('USD', Chain::pegCurrencyFor('dsusd'), 'Dassie\'s testnet dollar is worth a dollar');
        self::assertNull(Chain::pegCurrencyFor('XRP'), 'XRP is worth what the oracles say');
        self::assertNull(Chain::pegCurrencyFor('RLUSD'), 'an asset this chain does not list is priced like any other');
    }

    public function testTheDescriptorAcceptsAWalletAddressAndRefusesALedgerAccount(): void
    {
        $descriptor = Chain::descriptor();

        self::assertTrue($descriptor->isValidAccount('https://ilp.interledger-test.dev/ld-merchant'));
        self::assertFalse($descriptor->isValidAccount('GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA'));
    }
}
