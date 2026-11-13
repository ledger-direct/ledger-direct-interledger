<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;
use InvalidArgumentException;

/**
 * What "INTERLEDGER" means inside a PaymentIntent, as data: the chain
 * name the record carries, the intent types per asset, the shape of a
 * receiving account, and a descriptor an adapter's configuration screen
 * can render next to the core's own chains.
 *
 * Interledger is not a ledger, and that shows here in two places:
 *
 *  - **No native asset.** Every balance an Open Payments wallet holds is
 *    issued by the account-holding institution, so every amount in the
 *    record is an IssuedCurrencyAmount whose `issuer` is the merchant's
 *    wallet address. `nativeAsset` is therefore empty, and the core —
 *    which only knows native assets for XRPL and Stellar — treats an
 *    unknown chain as "everything is an issued currency" anyway.
 *  - **No account format of its own.** A receiving account is a wallet
 *    address, i.e. an `https://` URL. A payment pointer (`$host/path`) is
 *    input syntax only and is normalised on the way in, exactly as Dassie
 *    describes it.
 *
 * The asset list is static on purpose while this is a prototype: Open
 * Payments hands the assets of a wallet address out at runtime
 * (`assetCode`, `assetScale`), so what belongs in a catalogue is an open
 * question (handover §7, item 6). The merchant does not pick an asset
 * here — the wallet address already decides it; the list only exists so a
 * configuration screen can name what the prototype has been run against.
 */
final class Chain
{
    public const NAME = 'INTERLEDGER';

    public const LABEL = 'Interledger (Open Payments)';

    /**
     * What the customer never has to type. The reference lives in the
     * incoming payment's metadata, so it is the merchant's handle on the
     * payment in their wallet activity, not an instruction to the payer.
     */
    public const IDENTIFIER_LABEL = 'Reference';

    /**
     * An https wallet address with an optional port and path. A host
     * without a dot is allowed on purpose, so a locally running Dassie
     * node (`https://localhost:10100/pay/xrp`) can be configured during
     * development. Never a proof that the address exists or that the
     * merchant controls it — the same caveat the core's account patterns
     * carry.
     */
    public const ACCOUNT_PATTERN = '#^https://[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*(:\d{1,5})?(/[A-Za-z0-9._~%!$&\'()*+,;=:@-]+)*/?$#D';

    /**
     * Assets the prototype has been exercised with. EUR because both
     * Interledger test wallets are EUR/2; USD and XRP because the test
     * wallet and Dassie offer them; DSUSD because that is Dassie's own
     * testnet dollar.
     */
    public const ASSETS = ['EUR', 'USD', 'XRP', 'DSUSD'];

    /** The intent `type` for a wallet address holding $assetCode. */
    public static function typeFor(string $assetCode): string
    {
        if ($assetCode === '' || preg_match('/^[A-Za-z0-9]{1,12}$/D', $assetCode) !== 1) {
            throw new InvalidArgumentException("'{$assetCode}' is not a usable Open Payments asset code.");
        }

        return 'interledger-' . strtolower($assetCode) . '-payment';
    }

    /** The asset code a `type` was built from, or null when it is not ours. */
    public static function assetForType(string $type): ?string
    {
        if (preg_match('/^interledger-([a-z0-9]{1,12})-payment$/D', $type, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    /**
     * A wallet address as it is stored: a payment pointer becomes its URL
     * form, a trailing slash goes away, the host is lowercased. Dassie
     * accepts payment pointers "only as input syntax", and so do we — the
     * record and the configuration hold the URL.
     */
    public static function normalizeWalletAddress(string $input): string
    {
        $address = trim($input);

        if (str_starts_with($address, '$')) {
            $address = 'https://' . substr($address, 1);
        }

        $parts = parse_url($address);

        if ($parts === false || !isset($parts['host'])) {
            return $address;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path;
    }

    /** Whether $account can be a receiving wallet address. */
    public static function isWalletAddress(string $account): bool
    {
        return preg_match(self::ACCOUNT_PATTERN, $account) === 1;
    }

    /**
     * The quote currency an asset is pegged to, or null when it has none and
     * has to be priced. `DSUSD` is Dassie's testnet dollar and worth a dollar;
     * `XRP` is worth what the oracles say. Null for an asset this chain does
     * not list, which is then priced like any other.
     */
    public static function pegCurrencyFor(string $assetCode): ?string
    {
        $code = strtoupper($assetCode);
        $descriptor = self::descriptor();

        return $descriptor->hasAsset($code) ? $descriptor->asset($code)->pegCurrency : null;
    }

    /**
     * The opaque id at the end of an incoming payment URL — what the
     * record carries as `hash` once something has arrived. Open Payments
     * has no transaction hash, and this is the closest thing that
     * identifies the payment the merchant can look up.
     */
    public static function paymentIdFrom(string $incomingPaymentUrl): string
    {
        $path = rtrim((string) (parse_url($incomingPaymentUrl, PHP_URL_PATH) ?: $incomingPaymentUrl), '/');
        $id = substr($path, (int) strrpos($path, '/') + 1);

        if ($id === '') {
            throw new InvalidArgumentException("'{$incomingPaymentUrl}' carries no incoming payment id.");
        }

        return $id;
    }

    /**
     * The chain as a configuration screen sees it — the same shape the
     * core's ChainCatalog hands out, so an adapter can render this card
     * with the code it already has. Not registered in the catalogue: that
     * waits for the package release (handover §7, item 6).
     */
    public static function descriptor(): ChainDescriptor
    {
        return new ChainDescriptor(
            chain: self::NAME,
            label: self::LABEL,
            // No native asset: an Open Payments balance is always issued by the wallet provider.
            nativeAsset: '',
            networks: ['mainnet', 'testnet'],
            identifierLabel: self::IDENTIFIER_LABEL,
            accountPattern: self::ACCOUNT_PATTERN,
            assets: array_map(
                static fn (string $code): AssetDescriptor => new AssetDescriptor(
                    code: $code,
                    label: self::assetLabel($code),
                    native: false,
                    displayDecimals: $code === 'XRP' ? 5 : 2,
                    type: self::typeFor($code),
                    // Same code on both sides is the 1:1 peg the prototype allows; XRP needs a rate.
                    pegCurrency: match ($code) {
                        'EUR' => 'EUR',
                        'USD', 'DSUSD' => 'USD',
                        default => null,
                    },
                    // The issuer is the merchant's own wallet address and is only known at runtime.
                    issuers: [],
                ),
                self::ASSETS,
            ),
            // No accountEncoding: a wallet address carries no checksum to verify,
            // so the pattern is all there is — and the argument only exists in the
            // core past 0.10.0 anyway.
        );
    }

    private static function assetLabel(string $code): string
    {
        return match ($code) {
            'EUR' => 'EUR (wallet balance)',
            'USD' => 'USD (wallet balance)',
            'XRP' => 'XRP',
            'DSUSD' => 'DSUSD (Dassie testnet dollar)',
            default => $code,
        };
    }
}
