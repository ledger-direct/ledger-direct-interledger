<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use Hardcastle\LedgerDirect\Interledger\Client\SdkOpenPaymentsClient;
use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The Interledger composition root — the sibling of Core\LedgerDirect and
 * Core\Stellar\LedgerDirectStellar. A platform that accepts Interledger
 * alongside a ledger chain holds this next to those and passes the same
 * cross-cutting objects into each; lazily built, memoised, no static
 * state.
 *
 * The PSR-18 client is here for the core's PriceService only. The Open
 * Payments requests go through the SDK, which brings its own HTTP client
 * and signs with sodium — that is the reason this package exists next to
 * the core instead of inside it.
 */
final class LedgerDirectInterledger
{
    private ?OpenPaymentsInterface $openPayments = null;

    private ?PriceService $priceService = null;

    private ?ReferenceService $referenceService = null;

    private ?IncomingPaymentService $incomingPaymentService = null;

    private ?ReceiptSyncService $receiptSyncService = null;

    private ?SettlementPolicy $settlementPolicy = null;

    private ?SyncThrottle $syncThrottle = null;

    private function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly InterledgerRepositoryInterface $repository,
        private readonly ConfigProviderInterface $configProvider,
        private readonly Credentials $credentials,
        private readonly ?CacheInterface $cache,
        private readonly ?CacheInterface $tokenCache,
        private readonly ClockInterface $clock,
        private readonly string $nativeAssetTolerance,
        private readonly int $rateFreshTtlSeconds,
        private readonly ?OpenPaymentsInterface $openPaymentsOverride,
    ) {
    }

    /**
     * @param CacheInterface|null $cache shared across requests; backs the rate cache and the sync throttle
     * @param CacheInterface|null $tokenCache where the Open Payments access token may be kept; null keeps
     *     it in the process, which costs one grant request per web request. See SdkOpenPaymentsClient —
     *     passing a cache here means a bearer token lands in the shop's cache, which is the adapter's call.
     * @param OpenPaymentsInterface|null $openPayments a wallet to use instead of the SDK client; tests
     *     pass Testing\FakeOpenPayments
     */
    public static function create(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        LoggerInterface $logger,
        InterledgerRepositoryInterface $repository,
        ConfigProviderInterface $configProvider,
        Credentials $credentials,
        ?CacheInterface $cache = null,
        ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
        string $nativeAssetTolerance = SettlementPolicy::DEFAULT_NATIVE_ASSET_TOLERANCE,
        int $rateFreshTtlSeconds = PriceService::DEFAULT_FRESH_TTL_SECONDS,
        ?OpenPaymentsInterface $openPayments = null,
    ): self {
        return new self(
            $httpClient,
            $requestFactory,
            $logger,
            $repository,
            $configProvider,
            $credentials,
            $cache,
            $tokenCache,
            $clock ?? new SystemClock(),
            $nativeAssetTolerance,
            $rateFreshTtlSeconds,
            $openPayments,
        );
    }

    public function clock(): ClockInterface
    {
        return $this->clock;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function configProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function repository(): InterledgerRepositoryInterface
    {
        return $this->repository;
    }

    /**
     * The merchant's wallet, read from the configured receiving account
     * on first use — not at construction, so a shop with Interledger
     * switched off never touches the configuration.
     */
    public function openPayments(): OpenPaymentsInterface
    {
        return $this->openPayments ??= $this->openPaymentsOverride ?? new SdkOpenPaymentsClient(
            Chain::normalizeWalletAddress($this->configProvider->getDestinationAccount(Chain::NAME)),
            $this->credentials,
            $this->logger,
            $this->tokenCache,
            $this->clock,
        );
    }

    public function priceService(): PriceService
    {
        return $this->priceService ??= new PriceService(
            $this->httpClient,
            $this->requestFactory,
            $this->logger,
            $this->cache,
            $this->rateFreshTtlSeconds,
            $this->clock,
        );
    }

    public function referenceService(): ReferenceService
    {
        return $this->referenceService ??= new ReferenceService($this->repository);
    }

    public function incomingPaymentService(): IncomingPaymentService
    {
        return $this->incomingPaymentService ??= new IncomingPaymentService(
            $this->openPayments(),
            $this->priceService(),
            $this->referenceService(),
            $this->configProvider,
            $this->clock,
        );
    }

    public function receiptSyncService(): ReceiptSyncService
    {
        return $this->receiptSyncService ??= new ReceiptSyncService($this->openPayments(), $this->logger);
    }

    public function settlementPolicy(): SettlementPolicy
    {
        return $this->settlementPolicy ??= new SettlementPolicy($this->nativeAssetTolerance);
    }

    /** Null without a cache — see Core\LedgerDirect::syncThrottle(). */
    public function syncThrottle(): ?SyncThrottle
    {
        if ($this->cache === null) {
            return null;
        }

        return $this->syncThrottle ??= new SyncThrottle(
            $this->cache,
            $this->logger,
            PaymentStatus::MIN_SYNC_INTERVAL_SECONDS,
            $this->clock,
        );
    }

    public function paymentStatus(PaymentIntent $intent): PaymentStatus
    {
        return PaymentStatus::fromIntent($intent, $this->settlementPolicy(), $this->clock->now()->getTimestamp());
    }

    public function paymentRequest(PaymentIntent $intent): PaymentRequest
    {
        return PaymentRequest::fromIntent($intent);
    }
}
