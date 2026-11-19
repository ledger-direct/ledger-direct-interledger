<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Sync\LedgerSyncInterface;
use Brick\Math\BigInteger;
use Closure;
use Hardcastle\LedgerDirect\Interledger\Client\IncomingPayment;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * The fulfillment part: it reads an order's incoming payment and writes
 * what has arrived into the intent — the counterpart of the core's two
 * SyncServices, with nothing to store.
 *
 * That is the whole difference. On a ledger, a sync pulls a page of
 * transactions for a receiving account into a table and a matcher then
 * picks the ones that belong to an order. Open Payments has no ledger to
 * page through and no transaction to mirror: the per-order resource *is*
 * the record, it is kept by the merchant's own wallet, and reading it
 * answers exactly one order. So there is no table, no cursor, no dedup —
 * and `sync()` has nothing to do, which it says out loud below.
 *
 * `findFulfillmentFor()` is what a status endpoint calls, named and shaped
 * like the core's so an adapter's matching code does not branch.
 *
 * **The throttle is the adapter's to place.** The core's SyncThrottle
 * guards sync(), and sync() does nothing here: the request to the wallet
 * is the one findFulfillmentFor() makes. An adapter that throttles sync()
 * alone therefore holds back nothing over Interledger and asks the wallet
 * on every status call. It has to hold findFulfillmentFor() back itself,
 * with the same SyncThrottle and a mark per incoming payment (its URL as
 * the account), so one order's page cannot hold back another's.
 *
 * **A payment that is still arriving is not a partial payment.** An
 * Interledger payment travels as a stream of packets and takes seconds to
 * arrive, where a ledger transaction is there at once and whole. A read in
 * those seconds finds less than was asked for, and reporting that would
 * tell the customer that money is missing, and the shop that the order is
 * partly paid, while the rest is on its way. So an amount short of the
 * request is reported only once it stood still: read again a moment later
 * and unchanged. Seen on the testnet with a Dassie node on each side: a
 * payment of 1.11199 XRP took 3.6 seconds, and a status read 200 ms before
 * its end found 1.01199.
 */
final class ReceiptSyncService implements LedgerSyncInterface
{
    /** How long an amount short of the request has to stand still before it counts as all that was sent. */
    public const SETTLE_SECONDS = 2;

    private const STANDING_KEY = 'ledger-direct.interledger.standing.v1.';

    /** A day: longer than any incoming payment lives. */
    private const STANDING_TTL = 86400;

    /** @var Closure(int): void */
    private readonly Closure $wait;

    /**
     * @param CacheInterface|null $cache shared across requests; remembers an amount that stood
     *     still, so a real partial payment is waited for once and not on every read. Without
     *     one, or with one that forgets between requests, every read of a short amount waits
     * @param (Closure(int): void)|null $wait how the service lets seconds pass; tests pass their own
     */
    public function __construct(
        private readonly OpenPaymentsInterface $openPayments,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $cache = null,
        ?Closure $wait = null,
    ) {
        $this->wait = $wait ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Nothing to pull: an Interledger payment leaves no per-account
     * history to mirror into storage, and what an order received is read
     * from its own incoming payment by findFulfillmentFor(). Implemented so an
     * adapter can keep one throttle and one sync entry point across all
     * chains instead of special-casing this one.
     */
    public function sync(string $account, string $network): void
    {
    }

    /**
     * What has arrived for this intent, or null while nothing has.
     *
     * The id in the receipt is the incoming payment's — Open Payments has
     * no transaction hash, and the id is what identifies the payment for
     * everyone involved. Nothing is reported until something arrived, so
     * that "is there a hash?" keeps meaning "has anything been paid?" for
     * every adapter that already asks it that way.
     */
    public function findFulfillmentFor(PaymentIntent $intent): ?Receipt
    {
        if ($intent->chain !== Chain::NAME) {
            throw new InvalidArgumentException(
                "An Interledger receipt needs an INTERLEDGER intent, got '{$intent->chain}'."
            );
        }

        if ($intent->destinationAccount === '') {
            throw new InvalidArgumentException('The intent carries no incoming payment URL.');
        }

        try {
            $incoming = $this->openPayments->incomingPayment($intent->destinationAccount);
        } catch (OpenPaymentsException $exception) {
            /*
             * A wallet that cannot be reached is "nothing has arrived yet", not an
             * error for the customer: this read *is* the status endpoint's work, so
             * throwing here would take a payment page down with the wallet. The page
             * keeps waiting and asks again, and the reason is in the log.
             */
            $this->logger->warning('LedgerDirect: could not read an Interledger incoming payment', [
                'incoming_payment' => $intent->destinationAccount,
                'reference' => $intent->destinationTag,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$incoming->hasReceived()) {
            return null;
        }

        $incoming = $this->onceItStoodStill($intent, $incoming);

        if ($incoming === null) {
            return null;
        }

        /** @var ScaledAmount $received */
        $received = $incoming->receivedAmount;

        $this->logger->info('LedgerDirect: an Interledger payment arrived', [
            'incoming_payment' => $incoming->url,
            'reference' => $intent->destinationTag,
            'received' => $received->decimal() . ' ' . $received->assetCode,
            'completed' => $incoming->completed,
        ]);

        return new Receipt(
            url: $incoming->url,
            received: $received,
            issuer: is_array($intent->amountRequested)
                ? (string) $intent->amountRequested['issuer']
                : $this->openPayments->walletAddressUrl(),
            completed: $incoming->completed,
        );
    }

    /**
     * The incoming payment to report, or null while its amount is still
     * growing. Anything that is not "short of the request, in the right
     * asset, and not completed" is reported as read.
     */
    private function onceItStoodStill(PaymentIntent $intent, IncomingPayment $incoming): ?IncomingPayment
    {
        /** @var ScaledAmount $received */
        $received = $incoming->receivedAmount;

        if (!$this->isShort($intent, $incoming) || $this->isKnownToStand($incoming->url, $received)) {
            return $incoming;
        }

        ($this->wait)(self::SETTLE_SECONDS);

        try {
            $again = $this->openPayments->incomingPayment($incoming->url);
        } catch (OpenPaymentsException $exception) {
            // Not known whether it moved: nothing is reported, the next read decides.
            $this->logger->warning('LedgerDirect: could not read an Interledger incoming payment a second time', [
                'incoming_payment' => $incoming->url,
                'reference' => $intent->destinationTag,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$again->hasReceived()) {
            return null;
        }

        /** @var ScaledAmount $now */
        $now = $again->receivedAmount;

        if ($now->value === $received->value && $now->isSameAsset($received)) {
            $this->rememberStanding($again->url, $now);

            return $again;
        }

        if (!$this->isShort($intent, $again)) {
            return $again;
        }

        $this->logger->debug('LedgerDirect: an Interledger payment is still arriving', [
            'incoming_payment' => $again->url,
            'reference' => $intent->destinationTag,
            'received' => $now->decimal() . ' ' . $now->assetCode,
        ]);

        return null;
    }

    /**
     * Whether what arrived is less than was asked for and more may come:
     * the right asset, not completed, and below the incoming payment's own
     * amount (or, where the read does not carry it, the intent's).
     */
    private function isShort(PaymentIntent $intent, IncomingPayment $incoming): bool
    {
        $received = $incoming->receivedAmount;

        if ($received === null || $incoming->completed) {
            return false;
        }

        $expected = $incoming->incomingAmount ?? $this->requestedBy($intent, $received);

        if ($expected === null || !$expected->isSameAsset($received)) {
            return false;
        }

        return BigInteger::of($received->value)->isLessThan(BigInteger::of($expected->value));
    }

    /** The intent's requested amount at the received amount's scale; null when it is in another asset or unreadable. */
    private function requestedBy(PaymentIntent $intent, ScaledAmount $received): ?ScaledAmount
    {
        $requested = $intent->amountRequested;

        if (!is_array($requested) || ($requested['currency'] ?? null) !== $received->assetCode) {
            return null;
        }

        try {
            return ScaledAmount::fromDecimal((string) ($requested['value'] ?? ''), $received->assetCode, $received->assetScale);
        } catch (Throwable) {
            return null;
        }
    }

    private function isKnownToStand(string $url, ScaledAmount $received): bool
    {
        if ($this->cache === null) {
            return false;
        }

        try {
            return $this->cache->get($this->standingKey($url)) === $received->value;
        } catch (Throwable) {
            return false;
        }
    }

    private function rememberStanding(string $url, ScaledAmount $received): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->set($this->standingKey($url), $received->value, self::STANDING_TTL);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Interledger cache write failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function standingKey(string $url): string
    {
        return self::STANDING_KEY . hash('sha256', $url);
    }
}
