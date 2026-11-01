<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Testing;

use DateTimeImmutable;
use Hardcastle\LedgerDirect\Interledger\Client\IncomingPayment;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use Hardcastle\LedgerDirect\Interledger\Client\WalletAddress;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use InvalidArgumentException;
use Throwable;

/**
 * An Open Payments resource server in memory: it creates incoming
 * payments, hands them back, and lets a test say that money arrived.
 *
 * This is the double the services above are tested against, and the
 * double an adapter should use for its own tests — writing a Shopware or
 * Laravel test against a real wallet would mean a network call, a grant
 * and real test money per assertion.
 */
final class FakeOpenPayments implements OpenPaymentsInterface
{
    /** @var array<string, IncomingPayment> keyed by URL */
    private array $payments = [];

    /** @var list<array{amount: ScaledAmount, expiresAt: DateTimeImmutable, metadata: array<string, string>}> */
    public array $created = [];

    /** How often an incoming payment was read. */
    public int $reads = 0;

    /** How often the wallet address document was read — what an adapter's cache is meant to save. */
    public int $addressReads = 0;

    /** Set this and the next call of any kind throws it — the "wallet is down" case. */
    public ?Throwable $failWith = null;

    /** Set this and assertKeyRegistered() throws; null means the key is fine. */
    public ?Throwable $keyProblem = null;

    private int $nextId = 1;

    public function __construct(
        private readonly string $walletAddressUrl = 'https://ilp.example.test/merchant',
        private readonly string $assetCode = 'EUR',
        private readonly int $assetScale = 2,
        private readonly string $resourceServer = 'https://ilp.example.test/01020304',
        private readonly string $authServer = 'https://auth.example.test/01020304',
    ) {
    }

    public function walletAddressUrl(): string
    {
        return $this->walletAddressUrl;
    }

    public function walletAddress(): WalletAddress
    {
        $this->maybeFail();
        ++$this->addressReads;

        return new WalletAddress(
            id: $this->walletAddressUrl,
            assetCode: $this->assetCode,
            assetScale: $this->assetScale,
            authServer: $this->authServer,
            resourceServer: $this->resourceServer,
            publicName: 'Fake merchant',
        );
    }

    public function createIncomingPayment(
        ScaledAmount $amount,
        DateTimeImmutable $expiresAt,
        array $metadata,
    ): IncomingPayment {
        $this->maybeFail();

        $this->created[] = ['amount' => $amount, 'expiresAt' => $expiresAt, 'metadata' => $metadata];

        $payment = new IncomingPayment(
            url: $this->resourceServer . '/incoming-payments/' . $this->paymentId(),
            receivedAmount: new ScaledAmount('0', $amount->assetCode, $amount->assetScale),
            incomingAmount: $amount,
            completed: false,
            expiresAt: $expiresAt,
            metadata: $metadata,
            walletAddress: $this->walletAddressUrl,
        );

        return $this->payments[$payment->url] = $payment;
    }

    public function incomingPayment(string $url): IncomingPayment
    {
        $this->maybeFail();
        ++$this->reads;

        return $this->payments[$url] ?? throw new OpenPaymentsException("No incoming payment at {$url}.");
    }

    public function assertKeyRegistered(): void
    {
        if ($this->keyProblem !== null) {
            throw $this->keyProblem;
        }
    }

    /**
     * Test control: $decimal has arrived on this incoming payment. Set
     * $completed for what a resource server does once the full amount is
     * there; a partial payment leaves it false.
     */
    public function receive(string $url, string $decimal, bool $completed = false): IncomingPayment
    {
        $payment = $this->payments[$url] ?? throw new InvalidArgumentException("No incoming payment at {$url}.");

        return $this->payments[$url] = new IncomingPayment(
            url: $payment->url,
            receivedAmount: ScaledAmount::fromDecimal($decimal, $this->assetCode, $this->assetScale),
            incomingAmount: $payment->incomingAmount,
            completed: $completed,
            expiresAt: $payment->expiresAt,
            metadata: $payment->metadata,
            walletAddress: $payment->walletAddress,
        );
    }

    /** Test control: the only incoming payment created so far. */
    public function onlyPayment(): IncomingPayment
    {
        if (count($this->payments) !== 1) {
            throw new InvalidArgumentException(count($this->payments) . ' incoming payments exist, not one.');
        }

        return array_values($this->payments)[0];
    }

    /** Test control: an amount arrived in another asset than the wallet's. */
    public function receiveForeignAsset(string $url, string $decimal, string $assetCode, int $assetScale): IncomingPayment
    {
        $payment = $this->payments[$url] ?? throw new InvalidArgumentException("No incoming payment at {$url}.");

        return $this->payments[$url] = new IncomingPayment(
            url: $payment->url,
            receivedAmount: ScaledAmount::fromDecimal($decimal, $assetCode, $assetScale),
            incomingAmount: $payment->incomingAmount,
            completed: false,
            expiresAt: $payment->expiresAt,
            metadata: $payment->metadata,
            walletAddress: $payment->walletAddress,
        );
    }

    private function maybeFail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    /** Ids shaped like the test wallet's: a UUID, so nothing downstream parses them for meaning. */
    private function paymentId(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $this->nextId++);
    }
}
