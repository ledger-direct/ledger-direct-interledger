<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * What the payment page puts in front of the customer. On the ledger
 * chains that is a URI the wallet has to be handed together with an
 * amount and an identifier; here it is one string: **the incoming payment
 * URL is already the payment link** every Open Payments wallet
 * understands, amount and expiry included.
 *
 * So there is no `PaymentUri` builder in this package and nothing to
 * sign — the resource server built the link when the order was placed.
 * This class only names the parts a page shows around the QR code, and
 * refuses an intent from another chain.
 */
final readonly class PaymentRequest
{
    private function __construct(
        /** The incoming payment URL: the QR code's payload and the link to copy. */
        public string $uri,
        /** The merchant's wallet address — what the customer's wallet shows as the payee. */
        public string $walletAddress,
        /** The amount that has to arrive, as a decimal string in $assetCode. */
        public string $amount,
        public string $assetCode,
        /** The merchant's own handle on this payment; not something the customer enters. */
        public int $reference,
    ) {
    }

    public static function fromIntent(PaymentIntent $intent): self
    {
        if ($intent->chain !== Chain::NAME) {
            throw new InvalidArgumentException(
                "An Interledger payment request needs an INTERLEDGER intent, got '{$intent->chain}'."
            );
        }

        if (!is_array($intent->amountRequested)) {
            throw new InvalidArgumentException(
                'An Interledger amount is always an issued currency; this intent carries a bare number.'
            );
        }

        return new self(
            uri: $intent->destinationAccount,
            walletAddress: (string) $intent->amountRequested['issuer'],
            amount: (string) $intent->amountRequested['value'],
            assetCode: (string) $intent->amountRequested['currency'],
            reference: $intent->destinationTag,
        );
    }
}
