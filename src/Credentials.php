<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use InvalidArgumentException;

/**
 * The merchant's Open Payments client key: the key id the wallet
 * registered, and the Ed25519 private key that signs every request
 * (RFC 9421 HTTP message signatures).
 *
 * **The format is base64 of the PEM, on one line** — that is what the PHP
 * SDK decodes, and what a key pasted into a shop's configuration field
 * can be without newlines. Handing the PEM itself over fails inside the
 * SDK's signer with "Invalid key length", which is how an afternoon of P0
 * went; the check below says so before any request is made.
 *
 * The key id is checked against the wallet address's `jwks.json` by
 * OpenPaymentsInterface::assertKeyRegistered(). The P0 run failed with
 * `invalid_client: could not determine client` because the configured id
 * was the wallet's *name* while the registered id is a UUID — a mistake
 * worth one HTTP request to rule out.
 *
 * Never logged, never serialised: __debugInfo() redacts the key so a
 * var_dump() in someone's debugging session cannot leak it into a log.
 */
final readonly class Credentials
{
    private function __construct(
        public string $keyId,
        public string $privateKey,
    ) {
    }

    /**
     * @param string $keyId the id the wallet lists in its jwks.json (a UUID at the test wallet)
     * @param string $privateKeyBase64Pem base64 of the Ed25519 private key PEM, one line
     */
    public static function fromBase64Pem(string $keyId, string $privateKeyBase64Pem): self
    {
        $keyId = trim($keyId);
        $key = trim($privateKeyBase64Pem);

        if ($keyId === '') {
            throw new InvalidArgumentException('An Open Payments client needs a key id.');
        }

        if (preg_match('/\s/', $keyId) === 1) {
            throw new InvalidArgumentException('A key id carries no whitespace.');
        }

        if ($key === '') {
            throw new InvalidArgumentException('An Open Payments client needs a private key.');
        }

        $decoded = base64_decode($key, true);

        if ($decoded === false || !str_contains($decoded, 'PRIVATE KEY')) {
            throw new InvalidArgumentException(
                'The private key must be the base64 of its PEM on one line — the PEM itself is not accepted. '
                . 'Encode it with: base64 -w0 private.key'
            );
        }

        return new self($keyId, $key);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'privateKey' => '[redacted]'];
    }
}
