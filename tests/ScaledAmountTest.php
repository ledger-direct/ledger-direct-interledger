<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScaledAmountTest extends TestCase
{
    public function testADecimalBecomesTheUnscaledIntegerOfItsAsset(): void
    {
        self::assertSame('130', ScaledAmount::fromDecimal('1.30', 'EUR', 2)->value);
        self::assertSame('5', ScaledAmount::fromDecimal('5', 'EUR', 0)->value);
        self::assertSame('2200000000', ScaledAmount::fromDecimal('2.2', 'XRP', 9)->value);
    }

    public function testRoundingIsHalfUpAtTheAssetsScale(): void
    {
        self::assertSame('101', ScaledAmount::fromDecimal('1.005', 'EUR', 2)->value);
        self::assertSame('100', ScaledAmount::fromDecimal('1.004', 'EUR', 2)->value);
        self::assertSame('0', ScaledAmount::fromDecimal('0.004', 'EUR', 2)->value);
    }

    public function testTheDecimalCarriesTheAssetsScale(): void
    {
        self::assertSame('1.30', (new ScaledAmount('130', 'EUR', 2))->decimal());
        self::assertSame('0.00', (new ScaledAmount('0', 'EUR', 2))->decimal());
        self::assertSame('12', (new ScaledAmount('12', 'EUR', 0))->decimal());
        self::assertSame('0.000000001', (new ScaledAmount('1', 'XRP', 9))->decimal());
    }

    public function testWhatIsSentAndWhatIsRequestedAreTheSameNumber(): void
    {
        // The requested amount in the record is read back out of the amount that was
        // sent, so a quote can never ask for more than the incoming payment accepts.
        $amount = ScaledAmount::fromDecimal('1.005', 'EUR', 2);

        self::assertSame('1.01', $amount->decimal());
        self::assertSame('101', $amount->value);
    }

    public function testTheRecordShapeIsAnIssuedCurrencyWithTheWalletAddressAsIssuer(): void
    {
        self::assertSame(
            ['currency' => 'EUR', 'value' => '1.30', 'issuer' => 'https://ilp.test/merchant'],
            (new ScaledAmount('130', 'EUR', 2))->issuedCurrency('https://ilp.test/merchant'),
        );
    }

    public function testTheRequestShapeIsWhatOpenPaymentsExpects(): void
    {
        self::assertSame(
            ['value' => '130', 'assetCode' => 'EUR', 'assetScale' => 2],
            (new ScaledAmount('130', 'EUR', 2))->toArray(),
        );
    }

    public function testAnAmountIsReadBackFromAResponseBody(): void
    {
        $amount = ScaledAmount::fromArray(['value' => '130', 'assetCode' => 'EUR', 'assetScale' => 2]);

        self::assertSame('1.30', $amount->decimal());
    }

    public function testAMissingFieldIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScaledAmount::fromArray(['value' => '130', 'assetCode' => 'EUR']);
    }

    public function testOnlyANonNegativeIntegerIsAnAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScaledAmount('1.30', 'EUR', 2);
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScaledAmount('-1', 'EUR', 2);
    }

    public function testZeroIsNotPositiveAndEverythingElseIs(): void
    {
        self::assertFalse((new ScaledAmount('0', 'EUR', 2))->isPositive());
        self::assertTrue((new ScaledAmount('1', 'EUR', 2))->isPositive());
    }

    public function testAnIssuerIsRequiredForTheRecordShape(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScaledAmount('130', 'EUR', 2))->issuedCurrency('');
    }
}
