<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use DateTimeImmutable;
use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Price\PriceUnavailableException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The quote part of an Interledger payment: it creates the incoming
 * payment for an order and returns the PaymentIntent that describes it —
 * the sibling of the core's XRPL and Stellar PaymentIntentService, in
 * Lesart A (handover §4).
 *
 * Three things differ from a ledger chain, and all three come from Open
 * Payments having a resource where a ledger has an address:
 *
 *  - **The asset is not chosen, it is read.** A wallet address holds
 *    exactly one asset, and it says which (`assetCode`, `assetScale`).
 *    There is no `$baseAsset` parameter here; what the merchant can
 *    decide is whether that asset is accepted at all.
 *  - **The destination is per order.** `destination_account` carries the
 *    incoming payment's URL, not the merchant's wallet address — the
 *    wallet address lives in the configuration and travels in the record
 *    as the amount's `issuer`.
 *  - **A refresh creates a new resource.** An expired incoming payment
 *    accepts nothing, so a re-quote means a new URL with the *same*
 *    reference in its metadata. Because that costs an HTTP request and a
 *    resource at the merchant's wallet, an intent that is still valid is
 *    handed back untouched instead of being re-created.
 */
final class IncomingPaymentService
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly OpenPaymentsInterface $openPayments,
        private readonly PriceService $priceService,
        private readonly ReferenceService $referenceService,
        private readonly ConfigProviderInterface $configProvider,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * The intent for an order: the existing one while it is still valid,
     * a fresh incoming payment otherwise.
     *
     * @param float $total the order total in $quoteCurrency
     * @param string $quoteCurrency the shop's currency, e.g. 'EUR'
     * @param string|null $baseAsset the asset the caller expects the wallet to hold; null to take
     *     whatever it holds. The parameter sits here, in the core's position, so an adapter can
     *     call every chain's intent service the same way
     * @param PaymentIntent|null $existing what is already stored for this order, if anything
     * @param string|null $description what the merchant sees next to the payment in their wallet
     */
    public function quoteForOrder(
        float $total,
        string $quoteCurrency,
        ?string $baseAsset = null,
        ?PaymentIntent $existing = null,
        ?string $description = null,
    ): PaymentIntent {
        $wallet = $this->openPayments->walletAddress();
        $network = $this->configProvider->getNetwork(Chain::NAME);

        if ($baseAsset !== null && strtoupper($baseAsset) !== strtoupper($wallet->assetCode)) {
            throw new AssetNotAcceptedException(sprintf(
                'The wallet address %s holds %s, not %s — a payment method for %s cannot be quoted against it.',
                $wallet->id,
                $wallet->assetCode,
                strtoupper($baseAsset),
                strtoupper($baseAsset),
            ));
        }

        if (!$this->configProvider->isAssetEnabled(Chain::NAME, $wallet->assetCode)) {
            throw new AssetNotAcceptedException(
                "Payments in {$wallet->assetCode} over Interledger are not currently accepted."
            );
        }

        if ($existing !== null && $this->isStillValid($existing, $wallet->id, $wallet->assetCode, $quoteCurrency)) {
            return $existing;
        }

        [$exchangeRate, $amountDecimal] = $this->rateAndAmount($total, $quoteCurrency, $wallet->assetCode, $network);
        $amount = ScaledAmount::fromDecimal($amountDecimal, $wallet->assetCode, $wallet->assetScale);

        if (!$amount->isPositive()) {
            throw new InvalidArgumentException(
                "An order total of {$total} {$quoteCurrency} rounds to nothing in {$wallet->assetCode}."
            );
        }

        // A refresh keeps the reference: the XRPL's "same account, same tag" becomes
        // "same reference" once the destination is a resource per quote (handover §4).
        $reference = $this->reusableReference($existing, $wallet->id)
            ?? $this->referenceService->generateReference($wallet->id);

        $expiresAt = new DateTimeImmutable(
            '@' . ($this->clock->now()->getTimestamp() + $this->configProvider->getQuoteExpirySeconds())
        );

        $incoming = $this->openPayments->createIncomingPayment(
            $amount,
            $expiresAt,
            [
                'description' => $description ?? 'LedgerDirect order',
                'externalRef' => ReferenceService::externalRef($reference),
            ],
        );

        // The resource server's own echo is authoritative for both: it is what the
        // customer's wallet will read, and what stops paying when the quote is over.
        $requested = $incoming->incomingAmount ?? $amount;

        return PaymentIntent::quote(
            type: Chain::typeFor($wallet->assetCode),
            chain: Chain::NAME,
            network: $network,
            baseAsset: $wallet->assetCode,
            quoteCurrency: $quoteCurrency,
            pairing: $wallet->assetCode . '/' . $quoteCurrency,
            exchangeRate: $exchangeRate,
            amountRequested: $requested->issuedCurrency($wallet->id),
            destinationAccount: $incoming->url,
            destinationTag: $reference,
            expiry: ($incoming->expiresAt ?? $expiresAt)->getTimestamp(),
        );
    }

    /**
     * The reference a refresh keeps, or null when a fresh one has to be
     * drawn. Kept only for the *same* wallet address: the counter belongs
     * to a wallet, so a merchant who switches wallets starts over rather
     * than carrying a number from another counter into a new one.
     */
    private function reusableReference(?PaymentIntent $existing, string $walletAddress): ?int
    {
        if ($existing === null || $existing->chain !== Chain::NAME) {
            return null;
        }

        if (!is_array($existing->amountRequested) || ($existing->amountRequested['issuer'] ?? null) !== $walletAddress) {
            return null;
        }

        return $existing->destinationTag > 0 ? $existing->destinationTag : null;
    }

    /**
     * Whether a stored intent can be handed out again: same wallet, same
     * asset, same shop currency, and the incoming payment has not expired.
     * Anything else needs a new resource.
     */
    private function isStillValid(
        PaymentIntent $intent,
        string $walletAddress,
        string $assetCode,
        string $quoteCurrency,
    ): bool {
        if ($intent->chain !== Chain::NAME || $intent->destinationAccount === '') {
            return false;
        }

        if ($intent->baseAsset !== $assetCode || $intent->quoteCurrency !== $quoteCurrency) {
            return false;
        }

        if (!is_array($intent->amountRequested) || ($intent->amountRequested['issuer'] ?? null) !== $walletAddress) {
            return false;
        }

        return $intent->expiry !== null && $intent->expiry > $this->clock->now()->getTimestamp();
    }

    /**
     * The rate and the amount to ask for.
     *
     * A balance that is the shop's currency is a 1:1 peg and costs no oracle
     * call — a EUR wallet taking a EUR order is the prototype's main case, and
     * the wallet provider's balance *is* that currency. So is an asset the
     * chain declares as pegged to it, which is how Dassie's DSUSD prices a USD
     * order. Otherwise the core's oracles are asked, which covers XRP and
     * nothing else an Interledger wallet is likely to hold: fiat-to-fiat
     * conversion is a known gap of the prototype (handover §7, item 7).
     *
     * @return array{0: float, 1: string} the rate and the decimal amount
     */
    private function rateAndAmount(float $total, string $quoteCurrency, string $assetCode, string $network): array
    {
        $quote = strtoupper($quoteCurrency);
        $peg = Chain::pegCurrencyFor($assetCode);

        /*
         * 1:1 when the wallet's balance *is* the shop's currency: the same code
         * on both sides, or an asset the chain declares as pegged to it. Dassie's
         * DSUSD is the case that needs the second half — it is worth a dollar but
         * is not called USD, and no oracle has a rate for it.
         */
        if (strtoupper($assetCode) === $quote || ($peg !== null && strtoupper($peg) === $quote)) {
            return [1.0, (string) $total];
        }

        try {
            $priced = $this->priceService->quoteAmount($total, $quoteCurrency, $assetCode, $network);
        } catch (InvalidArgumentException | PriceUnavailableException $exception) {
            throw new NoPriceForAssetException(sprintf(
                'No rate for %s/%s: in this prototype an Interledger wallet in %s takes orders in %s (%s).',
                $assetCode,
                $quoteCurrency,
                $assetCode,
                $peg === null || strtoupper($peg) === strtoupper($assetCode)
                    ? strtoupper($assetCode)
                    : strtoupper($assetCode) . ' or ' . strtoupper($peg),
                $exception->getMessage(),
            ), 0, $exception);
        }

        return [$priced->exchangeRate, $priced->amount];
    }
}
