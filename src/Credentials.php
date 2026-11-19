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
 * **Two ways to be a client.** fromBase64Pem() is the client a wallet
 * address stands for: the wallet lists the key in its `jwks.json`, and the
 * key id is the one it lists. keyOnly() is a client known by its key alone
 * (Open Payments calls it directed identity): the public key travels in the
 * grant request, nothing is registered anywhere, and the key id is the
 * key's own RFC 7638 thumbprint. That is how a shop is a client of a Dassie
 * node, whose `jwks.json` lists the node's key and nobody else's; an
 * authorization server grants such a client only what needs no owner, which
 * is everything the receiving side asks for.
 *
 * Never logged, never serialised: __debugInfo() redacts the key so a
 * var_dump() in someone's debugging session cannot leak it into a log.
 */
final readonly class Credentials
{
    /** DER of an Ed25519 private key in PKCS#8 up to the seed: the 32 bytes after it are the key. */
    private const PKCS8_ED25519_PREFIX = '302e020100300506032b657004220420';

    private function __construct(
        public string $keyId,
        public string $privateKey,
        private bool $keyOnly = false,
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

        self::pemOf($key);

        return new self($keyId, $key);
    }

    /**
     * A client known by its key alone. The key id is the thumbprint of the
     * public key, so the same key is the same client wherever it asks, and
     * there is nothing to configure beside the key.
     *
     * @param string $privateKeyBase64Pem base64 of the Ed25519 private key PEM, one line
     */
    public static function keyOnly(string $privateKeyBase64Pem): self
    {
        $key = trim($privateKeyBase64Pem);
        $public = self::publicKey(self::pemOf($key));

        return new self(self::thumbprint($public), $key, true);
    }

    /** Whether the client is known by its key alone, with nothing registered at a wallet address. */
    public function isKeyOnly(): bool
    {
        return $this->keyOnly;
    }

    /**
     * The public key as the JWK a grant request carries for a key-only
     * client (`client: {jwk: …}`), under this client's key id.
     *
     * @return array{kid: string, alg: string, use: string, kty: string, crv: string, x: string}
     */
    public function publicJwk(): array
    {
        return [
            'kid' => $this->keyId,
            'alg' => 'EdDSA',
            'use' => 'sig',
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => self::base64Url(self::publicKey(self::pemOf($this->privateKey))),
        ];
    }

    /** The PEM inside the configured value, or the instruction for what the value has to be. */
    private static function pemOf(string $key): string
    {
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

        return $decoded;
    }

    /** The raw Ed25519 public key of a PKCS#8 private key PEM. */
    private static function publicKey(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) PRIVATE KEY-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);

        if ($der === false || strlen($der) !== 48 || bin2hex(substr($der, 0, 16)) !== self::PKCS8_ED25519_PREFIX) {
            throw new InvalidArgumentException('The private key is not an Ed25519 key in PKCS#8, which is what Open Payments signs with.');
        }

        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($der, 16)));
    }

    /** RFC 7638: SHA-256 over the key's required members, in lexicographic order, without whitespace. */
    private static function thumbprint(string $publicKey): string
    {
        $members = '{"crv":"Ed25519","kty":"OKP","x":"' . self::base64Url($publicKey) . '"}';

        return self::base64Url(hash('sha256', $members, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string, string|bool> */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'privateKey' => '[redacted]', 'keyOnly' => $this->keyOnly];
    }
}
