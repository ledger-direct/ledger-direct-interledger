<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Sync\LedgerSyncInterface;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

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
 * like the core's so an adapter's matching code does not branch. The
 * core's SyncThrottle still applies and still works unchanged: it is keyed
 * by network and account, and one request per interval per wallet address
 * is exactly the right budget here too.
 */
final class ReceiptSyncService implements LedgerSyncInterface
{
    public function __construct(
        private readonly OpenPaymentsInterface $openPayments,
        private readonly LoggerInterface $logger,
    ) {
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
}
