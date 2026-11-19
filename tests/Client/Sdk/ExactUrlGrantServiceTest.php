<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests\Client\Sdk;

use Exception;
use Hardcastle\LedgerDirect\Interledger\Client\Sdk\ExactUrlGrantService;
use Hardcastle\LedgerDirect\Interledger\Credentials;
use Hardcastle\LedgerDirect\Interledger\Tests\TestKey;
use OpenPayments\ApiClient;
use OpenPayments\Models\Grant;
use OpenPayments\Models\PendingGrant;
use PHPUnit\Framework\TestCase;

/**
 * The grant endpoint is the URL the wallet address names. The SDK appends a
 * slash; a Dassie node has no route `/gnap/` and answers 404.
 */
final class ExactUrlGrantServiceTest extends TestCase
{
    private const WALLET = 'https://shop.example/pay';

    private const ACCESS = ['access_token' => ['access' => [['type' => 'incoming-payment', 'actions' => ['create', 'read']]]]];

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    public function testTheRequestGoesToTheUrlAsTheWalletAddressNamesIt(): void
    {
        $grant = $this->service($this->fixture('dassie-incoming-payment-grant'))
            ->request(['url' => 'https://node.example/gnap'], self::ACCESS);

        self::assertInstanceOf(Grant::class, $grant);
        self::assertSame('test-access-token', $grant->access_token->value);
        self::assertSame(3600, $grant->access_token->expires_in);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://node.example/gnap', $this->requests[0]['url'], 'no slash appended');
    }

    public function testAUrlThatEndsInASlashKeepsIt(): void
    {
        $this->service($this->fixture('dassie-incoming-payment-grant'))->request(['url' => 'https://auth.example/'], self::ACCESS);

        self::assertSame('https://auth.example/', $this->requests[0]['url']);
    }

    public function testTheClientIsTheWalletAddressUnlessTheRequestCarriesAKey(): void
    {
        $service = $this->service($this->fixture('dassie-incoming-payment-grant'));
        $jwk = Credentials::keyOnly(TestKey::base64Pem())->publicJwk();

        $service->request(['url' => 'https://node.example/gnap'], self::ACCESS);
        $service->request(['url' => 'https://node.example/gnap'], self::ACCESS + ['client' => ['jwk' => $jwk]]);

        self::assertSame(self::WALLET, $this->requests[0]['body']['client']);
        self::assertSame(['jwk' => $jwk], $this->requests[1]['body']['client'], 'a key-only client names no wallet address');
    }

    public function testAnAnswerThatAsksForTheOwnerIsAPendingGrant(): void
    {
        $pending = $this->service([
            'interact' => ['redirect' => 'https://node.example/gnap/interact/1/2', 'finish' => 'nonce'],
            'continue' => ['access_token' => ['value' => 'c'], 'uri' => 'https://node.example/gnap/continue/1', 'wait' => 5],
        ])->request(['url' => 'https://node.example/gnap'], self::ACCESS);

        self::assertInstanceOf(PendingGrant::class, $pending);
    }

    public function testARefusalIsAnException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/invalid_client/');

        $this->service(['error' => true, 'message' => '401 invalid_client', 'status_code' => 401])
            ->request(['url' => 'https://node.example/gnap'], self::ACCESS);
    }

    public function testARequestTheSchemaRefusesIsNotSent(): void
    {
        try {
            $this->service([])->request(['url' => 'https://node.example/gnap'], ['access_token' => ['access' => [['type' => 'nonsense']]]]);
            self::fail('the SDK\'s validator should have refused the request');
        } catch (\Throwable) {
            self::assertSame([], $this->requests);
        }
    }

    /** @param array<string, mixed> $answer */
    private function service(array $answer): ExactUrlGrantService
    {
        $api = $this->createStub(ApiClient::class);
        $api->method('request')->willReturnCallback(function (string $method, string $url, array $body = []) use ($answer): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];

            return $answer;
        });

        return new ExactUrlGrantService($api, self::WALLET);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../../fixtures/{$name}.json"), true);
    }
}
