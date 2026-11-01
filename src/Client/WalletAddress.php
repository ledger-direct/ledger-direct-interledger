<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client;

use InvalidArgumentException;

/**
 * A wallet address as Open Payments publishes it: the address itself, the
 * single asset it holds, and the two servers everything else goes to.
 *
 * The asset is the reason this is read at all. Nothing in the shop's
 * configuration says which currency a wallet address holds — the address
 * does, at runtime, and both Interledger test wallets turned out to be
 * EUR/2 rather than the USD the handover assumed.
 */
final readonly class WalletAddress
{
    public function __construct(
        public string $id,
        public string $assetCode,
        public int $assetScale,
        public string $authServer,
        public string $resourceServer,
        public string $publicName = '',
    ) {
        foreach (['id' => $id, 'assetCode' => $assetCode, 'authServer' => $authServer, 'resourceServer' => $resourceServer] as $field => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("A wallet address is missing '{$field}'.");
            }
        }

        /*
         * The two servers come out of the wallet address document, and every
         * later request goes to them carrying the merchant's signature or an
         * access token. A document that names a plain-http server is not one
         * to follow — Open Payments is https throughout.
         */
        foreach (['id' => $id, 'authServer' => $authServer, 'resourceServer' => $resourceServer] as $field => $value) {
            if (!str_starts_with($value, 'https://')) {
                throw new InvalidArgumentException("A wallet address's '{$field}' must be an https URL, got '{$value}'.");
            }
        }

        if ($assetScale < 0) {
            throw new InvalidArgumentException("A wallet address's asset scale cannot be negative, got {$assetScale}.");
        }
    }

    /** @param array<string, mixed> $data the wallet address document */
    public static function fromArray(array $data): self
    {
        // The scale is not optional: a missing one read as 0 would turn every
        // amount into whole units, and 130 cents into 130 euro.
        if (!isset($data['assetScale'])) {
            throw new InvalidArgumentException("A wallet address is missing 'assetScale'.");
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            assetCode: (string) ($data['assetCode'] ?? ''),
            assetScale: (int) $data['assetScale'],
            authServer: (string) ($data['authServer'] ?? ''),
            resourceServer: (string) ($data['resourceServer'] ?? ''),
            publicName: (string) ($data['publicName'] ?? ''),
        );
    }
}
