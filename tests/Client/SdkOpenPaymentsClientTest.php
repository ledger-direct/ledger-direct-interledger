<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests\Client;

use DateTimeImmutable;
use Exception;
use Hardcastle\LedgerDirect\Core\Testing\FrozenClock;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\Client\SdkOpenPaymentsClient;
use Hardcastle\LedgerDirect\Interledger\Credentials;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use Hardcastle\LedgerDirect\Interledger\Tests\TestKey;
use OpenPayments\AuthClient;
use OpenPayments\Models\IncomingPayment as SdkPublicIncomingPayment;
use OpenPayments\Models\IncomingPaymentWithPaymentMethods;
use OpenPayments\Models\JsonWebKeySet;
use OpenPayments\Models\WalletAddress as SdkWalletAddress;
use OpenPayments\Services\GrantService;
use OpenPayments\Services\IncomingPaymentService as SdkIncomingPaymentService;
use OpenPayments\Services\WalletAddressService;
use OpenPayments\Transformers\GrantTransformer;
use OpenPayments\Validators\IncomingPaymentValidator;
use PHPUnit\Framework\TestCase;

/**
 * The one class in this package that talks to the SDK, tested against a
 * mocked SDK and the responses P0 recorded from the Interledger test
 * wallet. What it has to get right is the mapping in both directions and
 * the grant, and both are pinned here — including that the request body
 * passes the SDK's own JSON schema for an incoming payment, which is the
 * check a wallet would otherwise do over the network.
 */
final class SdkOpenPaymentsClientTest extends TestCase
{
    private const WALLET = 'https://ilp.interledger-test.dev/ld-merchant';

    private const KEY_ID = '4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77';

    /** A resource of the wallet in the fixture, which is what the client will read. */
    private const INCOMING_PAYMENT = 'https://ilp.interledger-test.dev/f537937b-7016-481b-b655-9f0d1014822c'
        . '/incoming-payments/cc294351-d4e2-411e-ae25-14b5118c8776';

    /** The node the Dassie fixtures were recorded from: a periphery node, reached through the default front door. */
    private const DASSIE_NODE = 'https://ihw5qnykc3qumkciobqodl6ha3hlzqbt3nn5pai.dassiepay.net';

    private WalletAddressService $walletService;

    private SdkIncomingPaymentService $incomingPayments;

    private GrantService $grants;

    private FrozenClock $clock;

    /** How often the authorization server was asked for a grant. */
    private int $grantRequests = 0;

    protected function setUp(): void
    {
        $this->walletService = $this->createStub(WalletAddressService::class);
        $this->walletService->method('get')->willReturn(new SdkWalletAddress($this->fixture('merchant-wallet-address')));
        $this->walletService->method('getKeys')->willReturn(new JsonWebKeySet($this->fixture('merchant-jwks')));

        $this->incomingPayments = $this->createStub(SdkIncomingPaymentService::class);
        $this->grants = $this->createStub(GrantService::class);
        $this->clock = new FrozenClock(1_790_000_000);
    }

    public function testTheWalletAddressNamesTheAssetAndTheTwoServers(): void
    {
        $wallet = $this->client()->walletAddress();

        self::assertSame(self::WALLET, $wallet->id);
        self::assertSame('EUR', $wallet->assetCode);
        self::assertSame(2, $wallet->assetScale);
        self::assertStringStartsWith('https://auth.interledger-test.dev/', $wallet->authServer);
        self::assertStringStartsWith('https://ilp.interledger-test.dev/', $wallet->resourceServer);
    }

    public function testCreatingAnIncomingPaymentSendsASpecValidRequestAndMapsTheResponse(): void
    {
        $this->expectGrant();
        $captured = null;

        $this->incomingPayments
            ->method('create')
            ->willReturnCallback(function (array $params, array $body) use (&$captured): IncomingPaymentWithPaymentMethods {
                $captured = ['params' => $params, 'body' => $body];

                return new IncomingPaymentWithPaymentMethods($this->fixture('incoming-payment-created'));
            });

        $payment = $this->client()->createIncomingPayment(
            new ScaledAmount('130', 'EUR', 2),
            new DateTimeImmutable('2026-10-31T21:59:06.523Z'),
            ['description' => 'LedgerDirect order 10001', 'externalRef' => 'LD-494349'],
        );

        self::assertNotNull($captured);
        self::assertSame('https://ilp.interledger-test.dev/f537937b-7016-481b-b655-9f0d1014822c', $captured['params']['url']);
        self::assertSame('test-access-token', $captured['params']['access_token']);
        self::assertSame(self::WALLET, $captured['body']['walletAddress']);
        self::assertSame(['value' => '130', 'assetCode' => 'EUR', 'assetScale' => 2], $captured['body']['incomingAmount']);
        self::assertSame('2026-10-31T21:59:06.523Z', $captured['body']['expiresAt'], 'RFC 3339 with milliseconds, in UTC');

        // The SDK validates this body against the Open Payments schema before sending it;
        // doing it here means a malformed request fails in this suite, not at the wallet.
        (new IncomingPaymentValidator())->validateRequest($captured['body']);

        self::assertSame(
            'https://ilp.interledger-test.dev/f537937b-7016-481b-b655-9f0d1014822c/incoming-payments/cc294351-d4e2-411e-ae25-14b5118c8776',
            $payment->url,
        );
        self::assertSame('1.30', $payment->incomingAmount?->decimal());
        self::assertSame('0.00', $payment->receivedAmount?->decimal());
        self::assertFalse($payment->completed);
        self::assertFalse($payment->hasReceived());
        self::assertSame('2026-10-31T21:59:06.523Z', $payment->expiresAt?->format('Y-m-d\TH:i:s.v\Z'));
        self::assertSame(494349, $payment->reference());
    }

    public function testASettledIncomingPaymentIsMappedWithItsReceivedAmount(): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('get')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('incoming-payment-completed')));

        $payment = $this->client()->incomingPayment(self::INCOMING_PAYMENT);

        self::assertTrue($payment->completed);
        self::assertTrue($payment->hasReceived());
        self::assertSame('1.30', $payment->receivedAmount?->decimal());
        self::assertSame('cc294351-d4e2-411e-ae25-14b5118c8776', $payment->paymentId());
    }

    /**
     * Open Payments does not promise that an incoming payment lives under the
     * advertised resource-server path, so a resource of the same wallet on
     * another path is read — refusing it would strand a paid order.
     */
    public function testAPaymentOnTheSameWalletButAnotherPathIsRead(): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('get')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('incoming-payment-completed')));

        $payment = $this->client()->incomingPayment('https://ilp.interledger-test.dev/incoming-payments/cc294351');

        self::assertTrue($payment->hasReceived());
    }

    public function testAPaymentOnAnotherHostIsNotReadEvenOverHttps(): void
    {
        $this->expectRefusal('https://wallet.example.invalid/incoming-payments/cc294351');
    }

    public function testAPaymentOnTheRightHostOverPlainHttpIsNotRead(): void
    {
        $this->expectRefusal('http://ilp.interledger-test.dev/incoming-payments/cc294351');
    }

    public function testAPaymentOnTheRightHostButAnotherPortIsNotRead(): void
    {
        $this->expectRefusal('https://ilp.interledger-test.dev:8443/incoming-payments/cc294351');
    }

    /**
     * The URL comes out of the order's record and the read carries the
     * merchant's token, so anything that is not this wallet's own origin is
     * refused before the token goes anywhere.
     */
    private function expectRefusal(string $url): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('get')->willReturnCallback(static function (): never {
            self::fail('a foreign URL must not be requested at all');
        });

        $this->expectException(OpenPaymentsException::class);
        $this->expectExceptionMessageMatches('/not a resource of/');

        $this->client()->incomingPayment($url);
    }

    public function testTheGrantIsAskedForOnceAndThenReused(): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('create')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('incoming-payment-created')));

        $client = $this->client();
        $client->createIncomingPayment(new ScaledAmount('130', 'EUR', 2), new DateTimeImmutable('@1790000900'), []);
        $client->createIncomingPayment(new ScaledAmount('130', 'EUR', 2), new DateTimeImmutable('@1790000900'), []);

        self::assertSame(1, $this->grantRequests, 'the token is kept for as long as it is good for');
    }

    public function testTheTokenCanBeSharedAcrossRequestsThroughACache(): void
    {
        $cache = new InMemoryCache();
        $this->expectGrant();
        $this->incomingPayments->method('create')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('incoming-payment-created')));

        $this->client($cache)->createIncomingPayment(new ScaledAmount('130', 'EUR', 2), new DateTimeImmutable('@1790000900'), []);
        // A second client stands for the next web request: with a cache it finds the
        // token instead of asking the authorization server again.
        $this->client($cache)->createIncomingPayment(new ScaledAmount('130', 'EUR', 2), new DateTimeImmutable('@1790000900'), []);

        self::assertSame(1, $this->grantRequests);
    }

    public function testAnInteractiveGrantIsReportedRatherThanRetried(): void
    {
        $this->grants->method('request')->willReturn($this->createStub(\OpenPayments\Models\PendingGrant::class));

        $this->expectException(OpenPaymentsException::class);
        $this->expectExceptionMessageMatches('/owner interaction/');

        $this->client()->createIncomingPayment(new ScaledAmount('130', 'EUR', 2), new DateTimeImmutable('@1790000900'), []);
    }

    public function testAnUnknownKeyIdNamesWhatTheWalletActuallyPublishes(): void
    {
        $client = new SdkOpenPaymentsClient(
            self::WALLET,
            Credentials::fromBase64Pem('ld-merchant', $this->privateKey()),
            new RecordingLogger(),
            null,
            $this->clock,
            fn (): AuthClient => $this->authClient(),
        );

        $this->expectException(OpenPaymentsException::class);
        $this->expectExceptionMessageMatches('/4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77/');

        $client->assertKeyRegistered();
    }

    public function testTheConfiguredKeyIdPassesWhenTheWalletKnowsIt(): void
    {
        $this->client()->assertKeyRegistered();

        $this->expectNotToPerformAssertions();
    }

    /**
     * A shop at a Dassie node: the node's `jwks.json` lists the node's own key
     * and nobody else's, so the shop is a client known by its key alone. The
     * grant request then carries the public key and names no wallet address.
     */
    public function testAKeyOnlyClientSendsItsPublicKeyInsteadOfAWalletAddress(): void
    {
        $captured = null;
        $this->grants->method('request')->willReturnCallback(function (array $params, array $body) use (&$captured): object {
            $captured = ['params' => $params, 'body' => $body];

            return (new GrantTransformer())->createFromResponse($this->fixture('dassie-incoming-payment-grant'));
        });
        $this->incomingPayments->method('create')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('dassie-incoming-payment-created')));

        $this->dassieClient()->createIncomingPayment(new ScaledAmount('10000000', 'XRP', 9), new DateTimeImmutable('@1790000900'), []);

        self::assertNotNull($captured);
        self::assertSame(self::DASSIE_NODE . '/gnap', $captured['params']['url'], 'the authorization server as the wallet address names it');
        self::assertSame(['jwk' => Credentials::keyOnly(TestKey::base64Pem())->publicJwk()], $captured['body']['client']);
        self::assertSame(TestKey::THUMBPRINT, $captured['body']['client']['jwk']['kid']);
        (new \OpenPayments\Validators\GrantValidator())->validateRequest($captured['body']);
    }

    public function testAKeyOnlyClientHasNoKeyListToBeHeldAgainst(): void
    {
        $walletService = $this->createStub(WalletAddressService::class);
        $walletService->method('getKeys')->willReturnCallback(static function (): never {
            self::fail('a key-only client is registered nowhere; the wallet\'s keys are not its business');
        });
        $this->walletService = $walletService;

        $this->dassieClient()->assertKeyRegistered();

        $this->expectNotToPerformAssertions();
    }

    public function testAWalletThatWantsTheOwnerForAKeyOnlyClientIsReportedAsSuch(): void
    {
        $this->grants->method('request')->willReturn($this->createStub(\OpenPayments\Models\PendingGrant::class));

        $this->expectException(OpenPaymentsException::class);
        $this->expectExceptionMessageMatches('/known by its key alone/');

        $this->dassieClient()->createIncomingPayment(new ScaledAmount('10000000', 'XRP', 9), new DateTimeImmutable('@1790000900'), []);
    }

    /**
     * What a Dassie node answers, recorded from a node on 0.4.5: XRP at nine
     * decimal places, a wallet address document with a `dassie` object the
     * specification lets a client ignore, and an incoming payment without
     * `updatedAt`.
     */
    public function testADassieNodesAnswersAreMapped(): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('create')
            ->willReturn(new IncomingPaymentWithPaymentMethods($this->fixture('dassie-incoming-payment-created')));
        $client = $this->dassieClient();

        $wallet = $client->walletAddress();
        self::assertSame('XRP', $wallet->assetCode);
        self::assertSame(9, $wallet->assetScale);
        self::assertSame(self::DASSIE_NODE, $wallet->resourceServer);

        $payment = $client->createIncomingPayment(new ScaledAmount('10000000', 'XRP', 9), new DateTimeImmutable('@1790000900'), []);
        self::assertSame(self::DASSIE_NODE . '/incoming-payments/daa477f5-9df9-419f-a7ad-a2d7c11e4ddc', $payment->url);
        self::assertSame('0.010000000', $payment->incomingAmount?->decimal());
        self::assertSame('0.000000000', $payment->receivedAmount?->decimal());
        self::assertFalse($payment->hasReceived());
        self::assertSame(494349, $payment->reference());
        self::assertSame('2026-11-19T06:43:11.075Z', $payment->expiresAt?->format('Y-m-d\TH:i:s.v\Z'));
    }

    /** Without the `id` the answer is the public view: what has arrived, and nothing else. */
    public function testADassieNodesPublicViewOfAPaymentCarriesTheReceivedAmount(): void
    {
        $this->expectGrant();
        $this->incomingPayments->method('get')
            ->willReturn(new SdkPublicIncomingPayment($this->fixture('dassie-incoming-payment-public')));

        $payment = $this->dassieClient()->incomingPayment(self::DASSIE_NODE . '/incoming-payments/daa477f5-9df9-419f-a7ad-a2d7c11e4ddc');

        self::assertSame('0.000000000', $payment->receivedAmount?->decimal());
        self::assertFalse($payment->hasReceived());
    }

    public function testAFailureFromTheSdkBecomesOneException(): void
    {
        $walletService = $this->createStub(WalletAddressService::class);
        $walletService->method('get')->willThrowException(new Exception('503 Service Unavailable'));
        $this->walletService = $walletService;

        $this->expectException(OpenPaymentsException::class);
        $this->expectExceptionMessageMatches('/503 Service Unavailable/');

        $this->client()->walletAddress();
    }

    private function client(?InMemoryCache $tokenCache = null): SdkOpenPaymentsClient
    {
        return new SdkOpenPaymentsClient(
            self::WALLET,
            Credentials::fromBase64Pem(self::KEY_ID, $this->privateKey()),
            new RecordingLogger(),
            $tokenCache,
            $this->clock,
            fn (): AuthClient => $this->authClient(),
        );
    }

    private function dassieClient(): SdkOpenPaymentsClient
    {
        $walletService = $this->createStub(WalletAddressService::class);
        $walletService->method('get')->willReturn(new SdkWalletAddress($this->fixture('dassie-wallet-address')));
        $walletService->method('getKeys')->willReturnCallback(static function (): never {
            self::fail('a key-only client does not read the wallet\'s keys');
        });
        $this->walletService = $walletService;

        return new SdkOpenPaymentsClient(
            self::DASSIE_NODE . '/pay/xrp',
            Credentials::keyOnly(TestKey::base64Pem()),
            new RecordingLogger(),
            null,
            $this->clock,
            fn (): AuthClient => $this->authClient(),
        );
    }

    private function authClient(): AuthClient
    {
        $sdk = $this->createStub(AuthClient::class);
        $sdk->method('walletAddress')->willReturn($this->walletService);
        $sdk->method('incomingPayment')->willReturn($this->incomingPayments);
        $sdk->method('grant')->willReturn($this->grants);

        return $sdk;
    }

    /** The non-interactive incoming-payment grant the test wallet hands out, counted. */
    private function expectGrant(): void
    {
        $this->grants->method('request')->willReturnCallback(function (): object {
            ++$this->grantRequests;

            return (new GrantTransformer())->createFromResponse($this->fixture('incoming-payment-grant'));
        });
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../fixtures/{$name}.json"), true);
    }

    private function privateKey(): string
    {
        return base64_encode("-----BEGIN PRIVATE KEY-----\nTk9UQVJFQUxLRVk=\n-----END PRIVATE KEY-----\n");
    }
}
