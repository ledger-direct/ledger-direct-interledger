<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Interledger\Chain;
use Hardcastle\LedgerDirect\Interledger\IncomingPaymentService;
use Hardcastle\LedgerDirect\Interledger\NoPriceForAssetException;
use Hardcastle\LedgerDirect\Interledger\ReferenceService;
use Hardcastle\LedgerDirect\Interledger\Testing\FakeOpenPayments;
use Hardcastle\LedgerDirect\Interledger\Testing\InMemoryInterledgerRepository;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class IncomingPaymentServiceTest extends TestCase
{
    private const WALLET = 'https://ilp.example.test/merchant';

    private FrozenClock $clock;

    private InMemoryInterledgerRepository $repository;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1_790_000_000);
        $this->repository = new InMemoryInterledgerRepository();
        $this->repository->scriptSequences(self::WALLET, [0, 1, 2]);
    }

    public function testAEuroOrderOnAEuroWalletIsQuotedOneToOne(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $intent = $this->service($wallet)->quoteForOrder(1.30, 'EUR', 'EUR', null, 'Order 10001');

        self::assertSame(Chain::NAME, $intent->chain);
        self::assertSame('interledger-eur-payment', $intent->type);
        self::assertSame('testnet', $intent->network);
        self::assertSame('EUR', $intent->baseAsset);
        self::assertSame('EUR', $intent->quoteCurrency);
        self::assertSame('EUR/EUR', $intent->pairing);
        self::assertSame(1.0, $intent->exchangeRate);
        self::assertSame(
            ['currency' => 'EUR', 'value' => '1.30', 'issuer' => self::WALLET],
            $intent->amountRequested,
            'the wallet address is the issuer of an Interledger balance',
        );
        self::assertSame($wallet->onlyPayment()->url, $intent->destinationAccount);
        self::assertSame(1_790_000_000 + 900, $intent->expiry);
        self::assertNull($intent->hash);
        self::assertNull($intent->amountPaid);
    }

    public function testTheRequestCarriesTheAmountAsAnUnscaledIntegerAndTheReferenceAsMetadata(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $intent = $this->service($wallet)->quoteForOrder(1.30, 'EUR', 'EUR', null, 'Order 10001');

        self::assertCount(1, $wallet->created);
        self::assertSame(
            ['value' => '130', 'assetCode' => 'EUR', 'assetScale' => 2],
            $wallet->created[0]['amount']->toArray(),
        );
        self::assertSame(
            ['description' => 'Order 10001', 'externalRef' => ReferenceService::externalRef($intent->destinationTag)],
            $wallet->created[0]['metadata'],
        );
        self::assertSame($intent->expiry, $wallet->created[0]['expiresAt']->getTimestamp());
    }

    public function testTheQuotedAmountIsExactlyWhatTheIncomingPaymentAccepts(): void
    {
        // A half-cent order: whatever the rounding does, the record and the resource
        // have to agree, or the order could never settle.
        $wallet = new FakeOpenPayments(self::WALLET);
        $intent = $this->service($wallet)->quoteForOrder(1.005, 'EUR');

        self::assertSame('1.01', $intent->amountRequested['value']);
        self::assertSame('101', $wallet->created[0]['amount']->value);
    }

    public function testADisabledAssetIsNotQuoted(): void
    {
        $this->expectException(AssetNotAcceptedException::class);

        $this->service(new FakeOpenPayments(self::WALLET), assetEnabled: false)->quoteForOrder(1.30, 'EUR');
    }

    public function testAnOrderThatRoundsToNothingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service(new FakeOpenPayments(self::WALLET))->quoteForOrder(0.001, 'EUR');
    }

    public function testAValidIntentIsHandedBackWithoutCreatingASecondResource(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $service = $this->service($wallet);

        $first = $service->quoteForOrder(1.30, 'EUR');
        $again = $service->quoteForOrder(1.30, 'EUR', 'EUR', $first);

        self::assertSame($first, $again);
        self::assertCount(1, $wallet->created, 'a still-valid quote must not cost another incoming payment');
    }

    public function testAnExpiredIntentIsReplacedByANewResourceWithTheSameReference(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $service = $this->service($wallet);

        $first = $service->quoteForOrder(1.30, 'EUR');
        $this->clock->advance(901);
        $second = $service->quoteForOrder(1.30, 'EUR', 'EUR', $first);

        self::assertNotSame($first->destinationAccount, $second->destinationAccount, 'a new resource, a new URL');
        self::assertSame($first->destinationTag, $second->destinationTag, 'the reference stays with the order');
        self::assertCount(2, $wallet->created);
        self::assertSame(
            ReferenceService::externalRef($first->destinationTag),
            $wallet->created[1]['metadata']['externalRef'],
        );
    }

    public function testAnIntentOnAnotherWalletIsNotReused(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $service = $this->service($wallet);

        $elsewhere = PaymentIntent::quote(
            type: 'interledger-eur-payment',
            chain: Chain::NAME,
            network: 'testnet',
            baseAsset: 'EUR',
            quoteCurrency: 'EUR',
            pairing: 'EUR/EUR',
            exchangeRate: 1.0,
            amountRequested: ['currency' => 'EUR', 'value' => '1.30', 'issuer' => 'https://ilp.example.test/old'],
            destinationAccount: 'https://ilp.example.test/old/incoming-payments/1',
            destinationTag: 123456,
            expiry: 1_790_000_900,
        );

        $fresh = $service->quoteForOrder(1.30, 'EUR', 'EUR', $elsewhere);

        self::assertSame(self::WALLET, $fresh->amountRequested['issuer']);
        self::assertNotSame(123456, $fresh->destinationTag, 'a different wallet means a different counter');
    }

    public function testAnIntentOfAnotherChainIsNotReused(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);

        $stellar = PaymentIntent::quote(
            type: 'stellar-xlm-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: 'XLM',
            quoteCurrency: 'EUR',
            pairing: 'XLM/EUR',
            exchangeRate: 0.1,
            amountRequested: 13.0,
            destinationAccount: 'GDEST',
            destinationTag: 123456,
            expiry: 1_790_000_900,
        );

        $fresh = $this->service($wallet)->quoteForOrder(1.30, 'EUR', 'EUR', $stellar);

        self::assertSame(Chain::NAME, $fresh->chain);
        self::assertCount(1, $wallet->created);
    }

    /**
     * Dassie's testnet dollar is worth a dollar and no oracle has a rate for
     * it, so a USD order is quoted at par from the peg the chain declares.
     */
    public function testAPeggedAssetIsQuotedAtParAgainstItsPegCurrency(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET, 'DSUSD', 2);
        $intent = $this->service($wallet)->quoteForOrder(19.99, 'USD', 'DSUSD');

        self::assertSame(1.0, $intent->exchangeRate);
        self::assertSame('DSUSD/USD', $intent->pairing);
        self::assertSame(
            ['currency' => 'DSUSD', 'value' => '19.99', 'issuer' => self::WALLET],
            $intent->amountRequested,
        );
        self::assertSame('1999', $wallet->created[0]['amount']->value);
    }

    public function testAWalletInAnotherCurrencyThanTheShopIsAKnownGap(): void
    {
        $this->expectException(NoPriceForAssetException::class);
        $this->expectExceptionMessageMatches('/DSUSD\/EUR/');

        $wallet = new FakeOpenPayments(self::WALLET, 'DSUSD', 2);
        $this->service($wallet)->quoteForOrder(1.30, 'EUR');
    }

    public function testADescriptionIsOptional(): void
    {
        $wallet = new FakeOpenPayments(self::WALLET);
        $this->service($wallet)->quoteForOrder(1.30, 'EUR');

        self::assertSame('LedgerDirect order', $wallet->created[0]['metadata']['description']);
    }

    private function service(FakeOpenPayments $wallet, bool $assetEnabled = true): IncomingPaymentService
    {
        $factory = new Psr17Factory();

        return new IncomingPaymentService(
            $wallet,
            new PriceService(new FakeHttpClient(), $factory, new RecordingLogger()),
            new ReferenceService($this->repository),
            new FakeConfigProvider('testnet', self::WALLET, $assetEnabled, 900),
            $this->clock,
        );
    }
}
