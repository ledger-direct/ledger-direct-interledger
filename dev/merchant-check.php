<?php

declare(strict_types=1);

/*
 * The merchant's side against a real wallet, through this package and nothing
 * else: read the wallet address, create one small incoming payment, read it
 * back. It is the check that a wallet takes this package's requests at all,
 * and how the two corrections to the SDK (Client\Sdk) were found against a
 * Dassie node.
 *
 *   php dev/merchant-check.php <wallet address>            a client known by its key alone
 *   php dev/merchant-check.php <wallet address> <key id>   a client the wallet registered under that id
 *
 * The private key comes from the environment only, as base64 of its PEM:
 *
 *   LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_PRIVATE_KEY
 *
 * A Dassie node lists nobody's key but its own, so a shop is a key-only client
 * there: any Ed25519 key will do, and the node grants it at once. The incoming
 * payment asks for a hundredth of the wallet's unit and expires in ten minutes.
 * Nothing secret is printed: no token, and not the payment's STREAM secret.
 */

require __DIR__ . '/../vendor/autoload.php';

use Hardcastle\LedgerDirect\Interledger\Client\SdkOpenPaymentsClient;
use Hardcastle\LedgerDirect\Interledger\Credentials;
use Hardcastle\LedgerDirect\Interledger\ScaledAmount;
use Psr\Log\NullLogger;

$walletUrl = $argv[1] ?? throw new RuntimeException('pass the wallet address, e.g. https://<node ID>.dassiepay.net/pay/xrp');
$key = getenv('LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_PRIVATE_KEY')
    ?: throw new RuntimeException('LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_PRIVATE_KEY is not set');
$credentials = isset($argv[2]) ? Credentials::fromBase64Pem($argv[2], $key) : Credentials::keyOnly($key);

$client = new SdkOpenPaymentsClient($walletUrl, $credentials, new NullLogger());
$client->assertKeyRegistered();

$wallet = $client->walletAddress();
printf("wallet   %s  %s, %d decimal places\n", $wallet->id, $wallet->assetCode, $wallet->assetScale);
printf("client   %s\n", $credentials->isKeyOnly() ? "known by its key alone, key id {$credentials->keyId}" : "the wallet address, key id {$credentials->keyId}");

$amount = new ScaledAmount(bcpow('10', (string) max(0, $wallet->assetScale - 2)), $wallet->assetCode, $wallet->assetScale);
$created = $client->createIncomingPayment(
    $amount,
    new DateTimeImmutable('+10 minutes'),
    ['description' => 'LedgerDirect merchant check', 'externalRef' => 'LD-' . random_int(100000, 999999)],
);
printf("created  %s\n         asks for %s %s, reference %s\n", $created->url, $created->incomingAmount?->decimal(), $wallet->assetCode, var_export($created->reference(), true));

$read = $client->incomingPayment($created->url);
printf("read     received %s, completed %s, expires %s\n", $read->receivedAmount?->decimal(), var_export($read->completed, true), $read->expiresAt?->format(DATE_ATOM));
