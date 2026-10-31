<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Hardcastle\LedgerDirect\Core\Clock\SystemClock;
use Hardcastle\LedgerDirect\Interledger\Credentials;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use OpenPayments\AuthClient;
use OpenPayments\Config\Config;
use OpenPayments\Models\Grant;
use OpenPayments\Models\IncomingPayment as SdkIncomingPayment;
use OpenPayments\Models\IncomingPaymentWithPaymentMethods;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * OpenPaymentsInterface over `interledger/open-payments-php`: GNAP grants,
 * RFC 9421 request signatures and the Open Payments 1.4.0 routes, as the
 * SDK implements them.
 *
 * Two things here are not the SDK's and need saying:
 *
 * **The grant.** Creating and reading incoming payments on one's own
 * wallet is approved without owner interaction, so this class asks for
 * that grant on first use and keeps the token for as long as the
 * authorization server says it is good for. An interactive answer means
 * the wallet is not configured as a client of its own address, and is
 * reported as such rather than retried.
 *
 * **Where the token is kept.** In the process by default, which costs one
 * extra grant request per web request. A payment page that polls every
 * five seconds is worth more than that, so a PSR-16 cache can be passed
 * in — with the deliberate consequence that a bearer token then sits in
 * the shop's cache. That is the adapter's decision to make, not this
 * package's, which is why it is a parameter and not the default.
 */
final class SdkOpenPaymentsClient implements OpenPaymentsInterface
{
    /** Open Payments timestamps are RFC 3339 with milliseconds. */
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private const CACHE_KEY_PREFIX = 'ledger-direct.interledger.grant.v1';

    /** Asked for once; `complete` is in there so a merchant can close a paid resource later. */
    private const INCOMING_PAYMENT_ACTIONS = ['create', 'read', 'complete', 'list'];

    /** Dropped from the token's lifetime, so a token never expires mid-request. */
    private const TOKEN_SAFETY_MARGIN_SECONDS = 30;

    private ?AuthClient $authClient = null;

    private ?WalletAddress $walletAddress = null;

    /** @var array{value: string, expires_at: int|null}|null */
    private ?array $token = null;

    private readonly ClockInterface $clock;

    /** @var Closure(string, Credentials): AuthClient */
    private readonly Closure $authClientFactory;

    /**
     * @param string $walletAddressUrl the merchant's receiving wallet address, normalised
     * @param CacheInterface|null $tokenCache shared across requests; holds the access token when given
     * @param (Closure(string, Credentials): AuthClient)|null $authClientFactory the SDK client, for tests
     */
    public function __construct(
        private readonly string $walletAddressUrl,
        private readonly Credentials $credentials,
        private readonly LoggerInterface $logger,
        private readonly ?CacheInterface $tokenCache = null,
        ?ClockInterface $clock = null,
        ?Closure $authClientFactory = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->authClientFactory = $authClientFactory ?? static fn (string $url, Credentials $credentials): AuthClient
            => new AuthClient(new Config($url, $credentials->privateKey, $credentials->keyId));
    }

    public function walletAddressUrl(): string
    {
        return $this->walletAddressUrl;
    }

    public function walletAddress(): WalletAddress
    {
        if ($this->walletAddress !== null) {
            return $this->walletAddress;
        }

        try {
            $address = $this->sdk()->walletAddress()->get(['url' => $this->walletAddressUrl]);
        } catch (Throwable $exception) {
            throw OpenPaymentsException::from("reading the wallet address {$this->walletAddressUrl}", $exception);
        }

        return $this->walletAddress = new WalletAddress(
            id: $address->id,
            assetCode: $address->assetCode,
            assetScale: $address->assetScale,
            authServer: $address->authServer,
            resourceServer: $address->resourceServer,
            publicName: $address->publicName,
        );
    }

    public function createIncomingPayment(
        ScaledAmount $amount,
        DateTimeImmutable $expiresAt,
        array $metadata,
    ): IncomingPayment {
        $wallet = $this->walletAddress();

        try {
            $created = $this->sdk()->incomingPayment()->create(
                ['url' => $wallet->resourceServer, 'access_token' => $this->accessToken()],
                [
                    'walletAddress' => $wallet->id,
                    'incomingAmount' => $amount->toArray(),
                    'metadata' => $metadata,
                    'expiresAt' => $expiresAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT),
                ],
            );
        } catch (Throwable $exception) {
            throw OpenPaymentsException::from('creating an incoming payment', $exception);
        }

        return $this->map($created->id, $created);
    }

    public function incomingPayment(string $url): IncomingPayment
    {
        try {
            $payment = $this->sdk()->incomingPayment()->get([
                'url' => $url,
                'access_token' => $this->accessToken(),
            ]);
        } catch (Throwable $exception) {
            throw OpenPaymentsException::from("reading the incoming payment {$url}", $exception);
        }

        return $this->map($url, $payment);
    }

    public function assertKeyRegistered(): void
    {
        try {
            $keys = $this->sdk()->walletAddress()->getKeys(['url' => $this->walletAddressUrl]);
        } catch (Throwable $exception) {
            throw OpenPaymentsException::from("reading the keys of {$this->walletAddressUrl}", $exception);
        }

        $registered = array_map(static fn (object $key): string => $key->kid, $keys->keys);

        if (in_array($this->credentials->keyId, $registered, true)) {
            return;
        }

        throw new OpenPaymentsException(sprintf(
            "The key id '%s' is not registered for %s. The wallet publishes %s. "
            . 'The id to configure is the one the wallet shows as "Key ID" — a UUID, not the wallet name.',
            $this->credentials->keyId,
            $this->walletAddressUrl,
            $registered === [] ? 'no keys at all' : "'" . implode("', '", $registered) . "'",
        ));
    }

    /**
     * The incoming-payment access token: from the process, from the cache,
     * or from a fresh grant, in that order.
     */
    private function accessToken(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->token !== null && ($this->token['expires_at'] === null || $this->token['expires_at'] > $now)) {
            return $this->token['value'];
        }

        $cached = $this->readCachedToken();

        if ($cached !== null) {
            $this->token = $cached;

            return $cached['value'];
        }

        $token = $this->requestGrant();
        $this->token = $token;
        $this->writeCachedToken($token);

        return $token['value'];
    }

    /** @return array{value: string, expires_at: int|null} */
    private function requestGrant(): array
    {
        $wallet = $this->walletAddress();

        try {
            $grant = $this->sdk()->grant()->request(
                ['url' => $wallet->authServer],
                [
                    'access_token' => ['access' => [[
                        'type' => 'incoming-payment',
                        'actions' => self::INCOMING_PAYMENT_ACTIONS,
                    ]]],
                    'client' => $this->walletAddressUrl,
                ],
            );
        } catch (Throwable $exception) {
            throw OpenPaymentsException::from('requesting an incoming-payment grant', $exception);
        }

        if (!$grant instanceof Grant || $grant->access_token === null) {
            throw new OpenPaymentsException(
                "The authorization server at {$wallet->authServer} wants owner interaction for an "
                . 'incoming-payment grant. A merchant reading their own wallet must not need that — check that '
                . "the configured key belongs to {$this->walletAddressUrl}."
            );
        }

        $expiresIn = $grant->access_token->expires_in;

        return [
            'value' => $grant->access_token->value,
            'expires_at' => $expiresIn === null
                ? null
                : $this->clock->now()->getTimestamp() + max(1, $expiresIn - self::TOKEN_SAFETY_MARGIN_SECONDS),
        ];
    }

    /** @return array{value: string, expires_at: int|null}|null */
    private function readCachedToken(): ?array
    {
        if ($this->tokenCache === null) {
            return null;
        }

        try {
            $entry = $this->tokenCache->get($this->cacheKey());
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Interledger token cache read failed', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!is_array($entry) || !is_string($entry['value'] ?? null) || $entry['value'] === '') {
            return null;
        }

        $expiresAt = $entry['expires_at'] ?? null;

        if ($expiresAt !== null && (!is_int($expiresAt) || $expiresAt <= $this->clock->now()->getTimestamp())) {
            return null;
        }

        return ['value' => $entry['value'], 'expires_at' => $expiresAt];
    }

    /** @param array{value: string, expires_at: int|null} $token */
    private function writeCachedToken(array $token): void
    {
        if ($this->tokenCache === null) {
            return;
        }

        // Without a stated lifetime the token is not cached: a bearer token of unknown
        // validity is not something to leave lying in a shop's cache indefinitely.
        if ($token['expires_at'] === null) {
            return;
        }

        $ttl = $token['expires_at'] - $this->clock->now()->getTimestamp();

        if ($ttl <= 0) {
            return;
        }

        try {
            $this->tokenCache->set($this->cacheKey(), $token, $ttl);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Interledger token cache write failed', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** Keyed by address and key id: a new key must not reuse the old key's token. */
    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . '.' . hash('sha256', $this->walletAddressUrl . '|' . $this->credentials->keyId);
    }

    private function sdk(): AuthClient
    {
        return $this->authClient ??= ($this->authClientFactory)($this->walletAddressUrl, $this->credentials);
    }

    /**
     * The SDK hands back the full resource for an authorized read and the
     * public view — received amount and nothing else — when the response
     * carries no `id`.
     */
    private function map(string $url, IncomingPaymentWithPaymentMethods|SdkIncomingPayment $payment): IncomingPayment
    {
        if ($payment instanceof SdkIncomingPayment) {
            return new IncomingPayment(
                url: $url,
                receivedAmount: $payment->receivedAmount === null
                    ? null
                    : new ScaledAmount(
                        $payment->receivedAmount->value,
                        $payment->receivedAmount->assetCode,
                        $payment->receivedAmount->assetScale,
                    ),
            );
        }

        return new IncomingPayment(
            url: $payment->id === '' ? $url : $payment->id,
            receivedAmount: $payment->receivedAmount === null
                ? null
                : new ScaledAmount(
                    $payment->receivedAmount->value,
                    $payment->receivedAmount->assetCode,
                    $payment->receivedAmount->assetScale,
                ),
            incomingAmount: $payment->incomingAmount === null
                ? null
                : new ScaledAmount(
                    $payment->incomingAmount->value,
                    $payment->incomingAmount->assetCode,
                    $payment->incomingAmount->assetScale,
                ),
            completed: $payment->completed,
            expiresAt: self::parseTimestamp($payment->expiresAt),
            metadata: $payment->metadata ?? [],
            walletAddress: $payment->walletAddress,
        );
    }

    private static function parseTimestamp(?string $timestamp): ?DateTimeImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
