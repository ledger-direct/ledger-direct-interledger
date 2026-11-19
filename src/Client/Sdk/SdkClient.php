<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client\Sdk;

use OpenPayments\AuthClient;
use OpenPayments\Config\Config;
use OpenPayments\Services\GrantService;
use OpenPayments\Services\IncomingPaymentService;
use OpenPayments\Services\WalletAddressService;

/**
 * The SDK's client with the two places put right where it does not speak
 * Open Payments the way a strict server reads it: the signature header
 * (SignedApiClient) and the grant endpoint's URL (ExactUrlGrantService).
 *
 * The SDK builds its HTTP client inside its constructor and hands it to its
 * services privately, so the three services this package uses are built
 * here, on the corrected client. Quotes, outgoing payments and token
 * management are the SDK's own, uncorrected: this package is the receiving
 * side and uses none of them.
 *
 * @internal
 */
final class SdkClient extends AuthClient
{
    private readonly SignedApiClient $api;

    private readonly string $walletAddressUrl;

    private ?WalletAddressService $walletAddresses = null;

    private ?IncomingPaymentService $incomingPayments = null;

    private ?GrantService $grants = null;

    public function __construct(Config $config)
    {
        parent::__construct($config);

        $this->api = new SignedApiClient($config->getPrivateKey(), $config->getKeyId());
        $this->walletAddressUrl = $config->getWalletAddressUrl();
    }

    public function walletAddress(): WalletAddressService
    {
        return $this->walletAddresses ??= new WalletAddressService($this->api);
    }

    public function incomingPayment(): IncomingPaymentService
    {
        return $this->incomingPayments ??= new IncomingPaymentService($this->api);
    }

    public function grant(): GrantService
    {
        return $this->grants ??= new ExactUrlGrantService($this->api, $this->walletAddressUrl);
    }
}
