<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Interledger\Chain;
use Hardcastle\LedgerDirect\Interledger\PaymentRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentRequestTest extends TestCase
{
    public function testThePaymentLinkIsTheIncomingPaymentUrl(): void
    {
        $request = PaymentRequest::fromIntent($this->intent());

        self::assertSame('https://ilp.example.test/0102/incoming-payments/abc', $request->uri);
        self::assertSame('https://ilp.example.test/merchant', $request->walletAddress);
        self::assertSame('1.30', $request->amount);
        self::assertSame('EUR', $request->assetCode);
        self::assertSame(494349, $request->reference);
    }

    public function testAnIntentOfAnotherChainIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentRequest::fromIntent(PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: 1.3,
            destinationAccount: 'rDest',
            destinationTag: 494349,
        ));
    }

    private function intent(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'interledger-eur-payment',
            chain: Chain::NAME,
            network: 'testnet',
            baseAsset: 'EUR',
            quoteCurrency: 'EUR',
            pairing: 'EUR/EUR',
            exchangeRate: 1.0,
            amountRequested: ['currency' => 'EUR', 'value' => '1.30', 'issuer' => 'https://ilp.example.test/merchant'],
            destinationAccount: 'https://ilp.example.test/0102/incoming-payments/abc',
            destinationTag: 494349,
            expiry: 1_790_000_900,
        );
    }
}
