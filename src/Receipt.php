<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * What pays for an Interledger intent: the incoming payment, and what it
 * has received so far. The sibling of the core's Xrpl\Fulfillment and
 * Stellar\Fulfillment, with the same `applyTo()` — so an adapter's
 * matching code is the same line for every chain.
 *
 * It is a single payment resource rather than a list of contributing
 * transactions, because that is how Open Payments counts: several STREAM
 * payments to one incoming payment add up into its `receivedAmount`
 * before anyone can read it. A top-up therefore needs nothing special
 * here — the amount simply grows.
 */
final readonly class Receipt
{
    public function __construct(
        /** The incoming payment's URL. */
        public string $url,
        /** What has arrived. */
        public ScaledAmount $received,
        /** The merchant's wallet address: the issuer of this balance. */
        public string $issuer,
        /** Whether the resource server considers the payment finished. */
        public bool $completed = false,
    ) {
    }

    /** The incoming payment's id — what the record carries as `hash`. */
    public function paymentId(): string
    {
        return Chain::paymentIdFrom($this->url);
    }

    /**
     * The intent with this receipt applied. `ctid` stays null: Interledger
     * has no compact transaction id, and no per-payment ledger event to
     * take one from.
     */
    public function applyTo(PaymentIntent $intent): PaymentIntent
    {
        return $intent->withFulfillment($this->paymentId(), $this->received->issuedCurrency($this->issuer), null);
    }
}
