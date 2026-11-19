<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client\Sdk;

use Exception;
use InvalidArgumentException;
use OpenPayments\ApiClient;
use OpenPayments\Models\Grant;
use OpenPayments\Models\PendingGrant;
use OpenPayments\Services\GrantService;
use OpenPayments\Transformers\GrantTransformer;
use OpenPayments\Transformers\PendingGrantTransformer;
use OpenPayments\Validators\GrantValidator;

/**
 * The SDK's grant request, sent to the URL the wallet address names as its
 * `authServer` - exactly that URL. The SDK appends a slash to it.
 *
 * Rafiki answers both. A Dassie node reads a URL strictly and has no route
 * `/gnap/`: the SDK's request came back as `404 not_found` (19 November
 * 2026, Dassie 0.4.5). The grant endpoint is whatever the wallet address
 * says it is, so the URL goes out unchanged.
 *
 * Everything else is the SDK's: the request is validated against its schema
 * and the answer goes through its transformers. Only `request()` is
 * replaced; continuing and cancelling a grant are not used by this package.
 *
 * To be removed when the SDK stops rewriting the URL.
 *
 * @internal
 */
final class ExactUrlGrantService extends GrantService
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly string $clientWalletAddress,
    ) {
        parent::__construct($api, $clientWalletAddress);
    }

    /**
     * @param array<string, mixed> $requestParams `url`: the authorization server
     * @param array<string, mixed> $grantRequest
     */
    public function request(array $requestParams, array $grantRequest): Grant|PendingGrant
    {
        $url = $requestParams['url'] ?? null;

        if (!is_string($url) || $url === '') {
            throw new InvalidArgumentException('A grant request needs the authorization server\'s URL.');
        }

        // As the SDK does: its own wallet address names the client, unless the caller
        // identifies it by its key alone (`['jwk' => ...]`, "directed identity").
        if (!is_array($grantRequest['client'] ?? null) || !isset($grantRequest['client']['jwk'])) {
            $grantRequest['client'] = $this->clientWalletAddress;
        }

        (new GrantValidator())->validateRequest($grantRequest);

        $response = $this->api->request('POST', $url, $grantRequest);

        if (isset($response['error'])) {
            // The SDK's own wording, so a caller's handling of its messages still applies.
            throw new Exception('Error: ' . print_r($response, true));
        }

        if (isset($response['interact'], $response['continue'])) {
            return (new PendingGrantTransformer())->createFromResponse($response);
        }

        return (new GrantTransformer())->createFromResponse($response);
    }
}
