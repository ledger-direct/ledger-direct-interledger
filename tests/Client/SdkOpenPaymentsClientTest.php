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
use OpenPayments\AuthClient;
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

        $payment = $this->client()->incomingPayment(
            'https://ilp.interledger-test.dev/f537937b/incoming-payments/cc294351-d4e2-411e-ae25-14b5118c8776'
        );

        self::assertTrue($payment->completed);
        self::assertTrue($payment->hasReceived());
        self::assertSame('1.30', $payment->receivedAmount?->decimal());
        self::assertSame('cc294351-d4e2-411e-ae25-14b5118c8776', $payment->paymentId());
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
