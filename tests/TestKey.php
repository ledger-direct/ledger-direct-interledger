<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

/**
 * The Ed25519 key of RFC 8037, appendix A: a key that is in a specification,
 * so nobody's, with a published public key and thumbprint to test against.
 */
final class TestKey
{
    /** RFC 8037 A.1, the private key `d`. */
    public const SEED_BASE64URL = 'nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A';

    /** RFC 8037 A.2, the public key `x`. */
    public const PUBLIC_BASE64URL = '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo';

    /** RFC 8037 A.3, the key's RFC 7638 thumbprint. */
    public const THUMBPRINT = 'kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k';

    /** The key as a shop's configuration holds it: base64 of the PKCS#8 PEM, on one line. */
    public static function base64Pem(): string
    {
        $der = hex2bin('302e020100300506032b657004220420') . self::decode(self::SEED_BASE64URL);

        return base64_encode("-----BEGIN PRIVATE KEY-----\n" . base64_encode($der) . "\n-----END PRIVATE KEY-----\n");
    }

    public static function publicKey(): string
    {
        return self::decode(self::PUBLIC_BASE64URL);
    }

    private static function decode(string $base64Url): string
    {
        return (string) base64_decode(strtr($base64Url, '-_', '+/'), true);
    }
}
