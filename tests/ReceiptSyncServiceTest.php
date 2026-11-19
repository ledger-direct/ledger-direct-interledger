<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Closure;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Interledger\Chain;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\ReceiptSyncService;
use Hardcastle\LedgerDirect\Interledger\Testing\FakeOpenPayments;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReceiptSyncServiceTest extends TestCase
{
    private const WALLET = 'https://ilp.example.test/merchant';

    private FakeOpenPayments $wallet;

    private RecordingLogger $logger;

    private ReceiptSyncService $service;

    /** How often the service waited for an amount to stand still, in seconds each. */
    private array $waits = [];

    /** What happens at the wallet while the service waits; null: nothing. */
    private ?Closure $meanwhile = null;

    protected function setUp(): void
    {
        $this->wallet = new FakeOpenPayments(self::WALLET);
        $this->logger = new RecordingLogger();
        $this->waits = [];
        $this->meanwhile = null;
        $this->service = $this->service();
    }

    private function service(?InMemoryCache $cache = null): ReceiptSyncService
    {
        return new ReceiptSyncService($this->wallet, $this->logger, $cache, function (int $seconds): void {
            $this->waits[] = $seconds;

            if ($this->meanwhile !== null) {
                ($this->meanwhile)();
            }
        });
    }

    public function testNothingArrivedIsNoFulfillment(): void
    {
        $intent = $this->quotedIntent();

        self::assertNull($this->service->findFulfillmentFor($intent));
        self::assertSame(0, $this->logger->count(), 'nothing happened, so nothing to log');
    }

    public function testAFullPaymentSettlesTheIntent(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.30', completed: true);

        $receipt = $this->service->findFulfillmentFor($intent);
        self::assertNotNull($receipt);
        self::assertTrue($receipt->completed);
        $paid = $receipt->applyTo($intent);

        self::assertSame(
            ['currency' => 'EUR', 'value' => '1.30', 'issuer' => self::WALLET],
            $paid->amountPaid,
        );
        self::assertSame($this->wallet->onlyPayment()->paymentId(), $paid->hash);
        self::assertNull($paid->ctid, 'Interledger has no CTID');
        self::assertSame(PaymentStatus::SETTLED, $this->stateOf($paid));
    }

    public function testThePaymentIdIsTheIncomingPaymentsOwnId(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.30', completed: true);

        self::assertSame(
            '00000000-0000-4000-8000-000000000001',
            $this->service->findFulfillmentFor($intent)?->applyTo($intent)->hash,
            'Open Payments has no transaction hash; the resource id is what identifies the payment',
        );
    }

    public function testAPartialPaymentIsRecordedWithItsShortfall(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '0.50');

        $paid = $this->service->findFulfillmentFor($intent)?->applyTo($intent);
        self::assertNotNull($paid);

        self::assertSame('0.50', $paid->amountPaid['value']);
        self::assertSame(PaymentStatus::PARTIAL, $this->stateOf($paid));
        // The core reports a shortfall as a plain decimal, trailing zeros stripped.
        self::assertSame('0.8', (new SettlementPolicy())->shortfall($paid));
        self::assertNotNull($paid->hash, 'something arrived, so the payment is identifiable');
        self::assertSame([ReceiptSyncService::SETTLE_SECONDS], $this->waits, 'reported once it stood still for a moment');
        self::assertSame(2, $this->wallet->reads);
    }

    /**
     * An Interledger payment arrives as a stream of packets over seconds. A read
     * in those seconds finds less than was asked for; that is not a customer who
     * paid too little. Seen with a Dassie node on each side: 1.01199 of 1.11199
     * XRP, 200 ms before the last packet.
     */
    public function testAPaymentThatIsStillArrivingAndThenCompleteIsReportedOnceAndInFull(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.20');
        $this->meanwhile = fn () => $this->wallet->receive($intent->destinationAccount, '1.30', completed: true);

        $paid = $this->service->findFulfillmentFor($intent)?->applyTo($intent);

        self::assertNotNull($paid);
        self::assertSame('1.30', $paid->amountPaid['value']);
        self::assertSame(PaymentStatus::SETTLED, $this->stateOf($paid), 'never a partial payment on the way');
        self::assertSame([ReceiptSyncService::SETTLE_SECONDS], $this->waits);
    }

    public function testAPaymentThatIsStillArrivingAfterTheWaitIsNotReportedYet(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '0.40');
        $this->meanwhile = fn () => $this->wallet->receive($intent->destinationAccount, '0.90');

        self::assertNull($this->service->findFulfillmentFor($intent), 'the next read decides');

        $record = $this->logger->records()[0];
        self::assertSame('debug', $record['level']);
        self::assertSame('0.90 EUR', $record['context']['received']);
    }

    public function testAnAmountThatReachesTheRequestWithoutBeingMarkedCompletedIsReported(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.00');
        $this->meanwhile = fn () => $this->wallet->receive($intent->destinationAccount, '1.30');

        $paid = $this->service->findFulfillmentFor($intent)?->applyTo($intent);

        self::assertNotNull($paid);
        self::assertSame(PaymentStatus::SETTLED, $this->stateOf($paid));
    }

    public function testAFullOrCompletedPaymentIsNeverWaitedFor(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.30');
        self::assertNotNull($this->service->findFulfillmentFor($intent));

        // Completed short of its amount: the wallet says nothing more will come.
        $this->wallet->receive($intent->destinationAccount, '0.50', completed: true);
        self::assertNotNull($this->service->findFulfillmentFor($intent));

        self::assertSame([], $this->waits);
    }

    public function testAPartialPaymentThatStoodStillIsRememberedAndNotWaitedForAgain(): void
    {
        $service = $this->service(new InMemoryCache());
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '0.50');

        self::assertNotNull($service->findFulfillmentFor($intent));
        self::assertNotNull($service->findFulfillmentFor($intent));
        self::assertSame([ReceiptSyncService::SETTLE_SECONDS], $this->waits, 'the second read of the same amount reports at once');

        // The top-up starts to arrive: the amount moved, so it is watched again.
        $this->wallet->receive($intent->destinationAccount, '0.90');
        $this->meanwhile = fn () => $this->wallet->receive($intent->destinationAccount, '1.30', completed: true);
        $paid = $service->findFulfillmentFor($intent)?->applyTo($intent);

        self::assertNotNull($paid);
        self::assertSame(PaymentStatus::SETTLED, $this->stateOf($paid));
        self::assertCount(2, $this->waits);
    }

    public function testWithoutACacheEveryReadOfAShortAmountWaits(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '0.50');

        self::assertNotNull($this->service->findFulfillmentFor($intent));
        self::assertNotNull($this->service->findFulfillmentFor($intent));

        self::assertCount(2, $this->waits, 'slower, never wrong: nothing depends on the cache keeping anything');
    }

    public function testAWalletThatCannotBeReadASecondTimeReportsNothingYet(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '0.50');
        $this->meanwhile = function (): void {
            $this->wallet->failWith = new OpenPaymentsException('503 Service Unavailable');
        };

        self::assertNull($this->service->findFulfillmentFor($intent));
        self::assertSame('warning', $this->logger->records()[0]['level']);
    }

    public function testAPaymentInAnotherAssetIsRecordedAsItArrived(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receiveForeignAsset($intent->destinationAccount, '2.00', 'USD', 2);

        $paid = $this->service->findFulfillmentFor($intent)?->applyTo($intent);
        self::assertNotNull($paid);

        self::assertSame('USD', $paid->amountPaid['currency']);
        self::assertSame(PaymentStatus::WRONG_ASSET, $this->stateOf($paid));
        self::assertSame([], $this->waits, 'another asset is not a shortfall that may still fill up');
    }

    public function testAnArrivedPaymentIsLoggedWithItsReference(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->receive($intent->destinationAccount, '1.30', completed: true);
        $this->service->findFulfillmentFor($intent);

        self::assertSame(1, $this->logger->count());
        $record = $this->logger->records()[0];
        self::assertSame('info', $record['level']);
        self::assertSame(494349, $record['context']['reference']);
        self::assertSame('1.30 EUR', $record['context']['received']);
        self::assertTrue($record['context']['completed']);
    }

    public function testAWalletThatCannotBeReachedReadsAsNothingArrivedYet(): void
    {
        $intent = $this->quotedIntent();
        $this->wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        self::assertNull($this->service->findFulfillmentFor($intent));
        self::assertSame('warning', $this->logger->records()[0]['level']);
    }

    public function testSyncingAnAccountHasNothingToDo(): void
    {
        $this->service->sync(self::WALLET, 'testnet');

        self::assertSame(0, $this->wallet->reads, 'there is no per-account history to pull');
    }

    public function testAnIntentOfAnotherChainIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->findFulfillmentFor(PaymentIntent::quote(
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

    private function quotedIntent(): PaymentIntent
    {
        $incoming = $this->wallet->createIncomingPayment(
            new \Hardcastle\LedgerDirect\Interledger\ScaledAmount('130', 'EUR', 2),
            new \DateTimeImmutable('@1790000900'),
            ['description' => 'Order 10001', 'externalRef' => 'LD-494349'],
        );

        return PaymentIntent::quote(
            type: 'interledger-eur-payment',
            chain: Chain::NAME,
            network: 'testnet',
            baseAsset: 'EUR',
            quoteCurrency: 'EUR',
            pairing: 'EUR/EUR',
            exchangeRate: 1.0,
            amountRequested: ['currency' => 'EUR', 'value' => '1.30', 'issuer' => self::WALLET],
            destinationAccount: $incoming->url,
            destinationTag: 494349,
            expiry: 1_790_000_900,
        );
    }

    private function stateOf(PaymentIntent $intent): string
    {
        return PaymentStatus::fromIntent($intent, new SettlementPolicy(), 1_790_000_100)->state();
    }
}
