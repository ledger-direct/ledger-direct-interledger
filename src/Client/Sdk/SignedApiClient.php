<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client\Sdk;

use OpenPayments\ApiClient;

/**
 * The SDK's HTTP client, with the `Signature` header written the way RFC 9421
 * defines it: a dictionary of byte sequences, `sig1=:<base64>:` (RFC 8941).
 * The SDK writes `sig1=<base64>` without the colons.
 *
 * Rafiki reads either. A Dassie node parses the header as the structured
 * field it is and answers the SDK's form with
 * `401 invalid_client: the signature is not accepted: Malformed` - found on
 * 19 November 2026 against a node on Dassie 0.4.5. The signature itself is
 * the SDK's and is not touched; only its framing is.
 *
 * To be removed when the SDK writes the standard form itself.
 *
 * @internal
 */
final class SignedApiClient extends ApiClient
{
    /**
     * @param string $method
     * @param string $url
     * @param string $jsonBody
     * @param array<string, mixed> $defaultHeaders
     *
     * @return array<string, mixed>
     */
    public function generateHttpSignature($method, $url, $jsonBody, $defaultHeaders)
    {
        $headers = parent::generateHttpSignature($method, $url, $jsonBody, $defaultHeaders);

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'signature' && is_string($value)) {
                $headers[$name] = self::asByteSequence($value);
            }
        }

        return $headers;
    }

    /**
     * `label=<base64>` becomes `label=:<base64>:`; a value that already is a
     * byte sequence, or is not of that shape at all, is left as it is.
     */
    public static function asByteSequence(string $signature): string
    {
        if (preg_match('/^([a-z*][a-z0-9_.*-]*)=([A-Za-z0-9+\/]+={0,2})$/', $signature, $parts) !== 1) {
            return $signature;
        }

        return $parts[1] . '=:' . $parts[2] . ':';
    }
}
