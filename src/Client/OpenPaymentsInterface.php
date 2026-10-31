<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client;

use DateTimeImmutable;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;

/**
 * The merchant's own Open Payments wallet, narrowed to the four things a
 * shop does with it: read the address, create an incoming payment per
 * order, read that payment back, and check that the configured key is one
 * the wallet knows.
 *
 * This package's own contract rather than the SDK's, for one concrete
 * reason: the SDK builds its Guzzle client inside the method that makes
 * the request, so nothing below it can be faked. With this interface the
 * services above are testable against Testing\FakeOpenPayments, and
 * SdkOpenPaymentsClient stays the one class that has to be exercised
 * against a real wallet.
 *
 * Grants are not part of the contract. Reading and creating incoming
 * payments on one's own wallet needs no owner interaction (confirmed in
 * P0 against the test wallet, and specified for Dassie), so the token
 * dance belongs inside the implementation — an adapter never sees it.
 */
interface OpenPaymentsInterface
{
    /** The merchant's wallet address, as configured. */
    public function walletAddressUrl(): string;

    /** The wallet address document, including the asset it holds. */
    public function walletAddress(): WalletAddress;

    /**
     * Creates the incoming payment for one order.
     *
     * @param array<string, string> $metadata `description` and `externalRef`; the reference
     *     travels here, because Open Payments has no field the customer could tag a payment with
     */
    public function createIncomingPayment(
        ScaledAmount $amount,
        DateTimeImmutable $expiresAt,
        array $metadata,
    ): IncomingPayment;

    /** Reads one incoming payment by its URL. */
    public function incomingPayment(string $url): IncomingPayment;

    /**
     * Throws when the configured key id is not one the wallet address
     * publishes in its `jwks.json`. One request, and it turns the
     * authorization server's `invalid_client: could not determine client`
     * into a sentence an admin can act on.
     */
    public function assertKeyRegistered(): void;
}
