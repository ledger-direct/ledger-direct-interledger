<?php

declare(strict_types=1);

/*
 * One Open Payments round trip against a real wallet, by hand — the tool the
 * prototype was built with, kept because it is the only way to see the whole
 * flow without a shop: both sides, both grants, the interactive one included.
 *
 *   php dev/round-trip.php merchant-request        incoming payment on the merchant wallet
 *   php dev/round-trip.php customer-grant          quote + interactive outgoing-payment grant
 *   php dev/round-trip.php customer-pay <redirect> continue that grant, create the outgoing payment
 *   php dev/round-trip.php merchant-poll           poll the incoming payment until it is completed
 *
 * Two accounts at an Open Payments wallet are needed (the Interledger test
 * wallet does), each with a developer key. Credentials come from the
 * environment only, never from a file in the repository:
 *
 *   LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_WALLET_ADDRESS
 *   LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_KEY_ID
 *   LEDGERDIRECT_INTERLEDGER_TESTNET_MERCHANT_PRIVATE_KEY   base64 of the PEM, one line
 *   …_CUSTOMER_… the same three for the paying side
 *
 * `dev/state.json` carries tokens and resource URLs between the subcommands
 * and is not in the repository. The responses it writes to `dev/fixtures/`
 * are where `tests/fixtures/` came from; scrub them before committing any.
 *
 * It talks to the SDK directly, not through this package: what it verifies is
 * the wallet and the flow, which is exactly what the package's own tests
 * replace with Testing\FakeOpenPayments.
 */

require __DIR__ . '/../vendor/autoload.php';


use OpenPayments\AuthClient;
use OpenPayments\Config\Config;

$env = static fn (string $k): string => $_ENV[$k] ?? getenv($k) ?: throw new RuntimeException("missing $k");
$stateFile = __DIR__ . '/state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$t0 = microtime(true);
$log = static fn (string $m) => fwrite(STDOUT, sprintf("[%5.1fs] %s\n", microtime(true) - $t0, $m));
$save = static function (array $s) use ($stateFile): void { file_put_contents($stateFile, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); };
$fixture = static function (string $name, mixed $data): void {
    @mkdir(__DIR__ . '/fixtures');
    file_put_contents(__DIR__ . "/fixtures/$name.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
$client = static function (string $who) use ($env, $log): array {
    $p = 'LEDGERDIRECT_INTERLEDGER_TESTNET_' . strtoupper($who) . '_';
    $walletUrl = $env($p . 'WALLET_ADDRESS');
    // The SDK itself expects base64(PEM) - one line - which is exactly how the wallet file holds the key.
    $key = $env($p . 'PRIVATE_KEY');
    // The key id must be the one the wallet registered (its jwks.json lists it); the file may hold the wallet name instead.
    $keyId = $env($p . 'KEY_ID');
    $jwks = json_decode((string) file_get_contents($walletUrl . '/jwks.json'), true);
    $kids = array_column($jwks['keys'] ?? [], 'kid');
    if (!in_array($keyId, $kids, true) && count($kids) === 1) { $log("$who: KEY_ID '$keyId' is not registered; using the wallet's only key id {$kids[0]}"); $keyId = $kids[0]; }
    $config = new Config($walletUrl, $key, $keyId);
    $op = new AuthClient($config);
    $wallet = $op->walletAddress()->get(['url' => $config->getWalletAddressUrl()]);
    return [$op, $config, $wallet];
};
$step = $argv[1] ?? '';

switch ($step) {
    case 'merchant-request':
        [$op, $config, $wallet] = $client('merchant');
        $fixture('merchant-wallet-address', $wallet);
        $log("merchant wallet: {$wallet->id} asset {$wallet->assetCode}/{$wallet->assetScale} authServer {$wallet->authServer}");
        $grant = $op->grant()->request(['url' => $wallet->authServer], [
            'access_token' => ['access' => [['type' => 'incoming-payment', 'actions' => ['read', 'complete', 'create', 'list']]]],
            'client' => $config->getWalletAddressUrl(),
        ]);
        if (!$grant instanceof \OpenPayments\Models\Grant) { throw new RuntimeException('incoming-payment grant unexpectedly interactive'); }
        $log('incoming-payment grant: non-interactive, token received');
        $reference = random_int(100000, 999999);
        $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d\TH:i:s.v\Z');
        $incoming = $op->incomingPayment()->create(['url' => $wallet->resourceServer, 'access_token' => $grant->access_token->value], [
            'walletAddress' => $config->getWalletAddressUrl(),
            'incomingAmount' => ['value' => '130', 'assetCode' => $wallet->assetCode, 'assetScale' => $wallet->assetScale],
            'metadata' => ['description' => 'LedgerDirect P0 order 10001', 'externalRef' => "LD-$reference"],
            'expiresAt' => $expiresAt,
        ]);
        $fixture('incoming-payment-created', $incoming);
        $log("incoming payment: {$incoming->id} expects {$incoming->incomingAmount->value} received {$incoming->receivedAmount->value} completed=" . var_export($incoming->completed, true) . " expiresAt {$incoming->expiresAt}");
        $state['merchant'] = ['incoming_payment_url' => $incoming->id, 'access_token' => $grant->access_token->value, 'manage' => $grant->access_token->manage, 'reference' => $reference, 'resource_server' => $wallet->resourceServer];
        $save($state);
        break;

    case 'customer-grant':
        [$op, $config, $wallet] = $client('customer');
        $fixture('customer-wallet-address', $wallet);
        $receiver = $state['merchant']['incoming_payment_url'] ?? throw new RuntimeException('run merchant-request first');
        $log("customer wallet: {$wallet->id} asset {$wallet->assetCode}/{$wallet->assetScale}");
        $qGrant = $op->grant()->request(['url' => $wallet->authServer], [
            'access_token' => ['access' => [['type' => 'quote', 'actions' => ['create', 'read']]]],
            'client' => $config->getWalletAddressUrl(),
        ]);
        if (!$qGrant instanceof \OpenPayments\Models\Grant) { throw new RuntimeException('quote grant unexpectedly interactive'); }
        $quote = $op->quote()->create(['url' => $wallet->resourceServer, 'access_token' => $qGrant->access_token->value], [
            'method' => 'ilp', 'walletAddress' => $wallet->id, 'receiver' => $receiver,
        ]);
        $fixture('quote', $quote);
        $log("quote: debit {$quote->debitAmount->value} {$quote->debitAmount->assetCode}, receive {$quote->receiveAmount->value} {$quote->receiveAmount->assetCode}, expires {$quote->expiresAt}");
        $nonce = bin2hex(random_bytes(8));
        $opGrant = $op->grant()->request(['url' => $wallet->authServer], [
            'access_token' => ['access' => [[
                'type' => 'outgoing-payment', 'actions' => ['create', 'read'], 'identifier' => $wallet->id,
                'limits' => ['receiver' => $receiver, 'debitAmount' => ['value' => $quote->debitAmount->value, 'assetCode' => $quote->debitAmount->assetCode, 'assetScale' => $quote->debitAmount->assetScale]],
            ]]],
            'client' => $config->getWalletAddressUrl(),
            'interact' => ['start' => ['redirect'], 'finish' => ['method' => 'redirect', 'uri' => 'https://localhost/ledger-direct-p0?order=10001', 'nonce' => $nonce]],
        ]);
        if (!$opGrant instanceof \OpenPayments\Models\PendingGrant) { throw new RuntimeException('outgoing-payment grant was not interactive'); }
        $state['customer'] = ['quote_url' => $quote->id, 'continue_token' => $opGrant->continue->access_token->value, 'continue_uri' => $opGrant->continue->uri, 'nonce' => $nonce, 'resource_server' => $wallet->resourceServer];
        $save($state);
        $log('outgoing-payment grant is pending. APPROVE HERE, then paste the URL the browser lands on:');
        fwrite(STDOUT, "\n    {$opGrant->interact->redirect}\n\n");
        break;

    case 'customer-pay':
        $redirect = $argv[2] ?? throw new RuntimeException('pass the redirect URL');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
        $interactRef = $q['interact_ref'] ?? throw new RuntimeException('no interact_ref in the URL');
        [$op, $config, $wallet] = $client('customer');
        $grant = $op->grant()->continue(['access_token' => $state['customer']['continue_token'], 'url' => $state['customer']['continue_uri']], ['interact_ref' => $interactRef]);
        if (!$grant instanceof \OpenPayments\Models\Grant) { throw new RuntimeException('grant not finalized'); }
        $log('outgoing-payment grant finalized');
        $outgoing = $op->outgoingPayment()->create(['url' => $wallet->resourceServer, 'access_token' => $grant->access_token->value], [
            'walletAddress' => $config->getWalletAddressUrl(), 'quoteId' => $state['customer']['quote_url'],
            'metadata' => ['description' => 'LedgerDirect P0 order 10001'],
        ]);
        $fixture('outgoing-payment-created', $outgoing);
        $log("outgoing payment: {$outgoing->id} sent {$outgoing->sentAmount->value} debit {$outgoing->debitAmount->value} receive {$outgoing->receiveAmount->value} failed=" . var_export($outgoing->failed, true));
        $state['customer']['outgoing_payment_url'] = $outgoing->id; $state['customer']['access_token'] = $grant->access_token->value;
        $save($state);
        break;

    case 'merchant-poll':
        [$op, $config, $wallet] = $client('merchant');
        $url = $state['merchant']['incoming_payment_url'];
        for ($i = 0; $i < 20; $i++) {
            $ip = $op->incomingPayment()->get(['url' => $url, 'access_token' => $state['merchant']['access_token']]);
            $log("received {$ip->receivedAmount->value}/{$ip->incomingAmount->value} {$ip->receivedAmount->assetCode} completed=" . var_export($ip->completed, true));
            if ($ip->completed || $ip->receivedAmount->value >= $ip->incomingAmount->value) { $fixture('incoming-payment-completed', $ip); break; }
            sleep(3);
        }
        break;

    default:
        fwrite(STDERR, "usage: round-trip.php merchant-request | customer-grant | customer-pay <url> | merchant-poll\n"); exit(2);
}
