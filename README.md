# LedgerDirect for Interledger

Accepts [Open Payments](https://openpayments.dev) incoming payments as a LedgerDirect chain, so a shop can
take an Interledger payment the same way it takes one on the XRP Ledger or Stellar: one `PaymentIntent`, one
settlement rule, one payment page.

It works against any Open Payments 1.4.0 wallet — a [Dassie](https://dassie.land) node you run yourself, the
[Interledger test wallet](https://wallet.interledger-test.dev), or any Rafiki-based wallet — because Open
Payments, not Dassie, is the interface.

> **Status: prototype.** Test money only, PHP 8.3 and up (the SDK's floor), and the known gaps below are real.
> It is not a release.

## Install

```bash
composer require hardcastle/ledger-direct-interledger
```

Brings [`interledger/open-payments-php`](https://github.com/interledger/open-payments-php) (GNAP grants and
RFC 9421 HTTP message signatures) and `hardcastle/ledger-direct-core`. Needs `ext-sodium`.

## What a merchant configures

| | |
|---|---|
| **Wallet address** | the receiving address, e.g. `https://ilp.interledger-test.dev/acme`. A payment pointer (`$ilp.interledger-test.dev/acme`) is input syntax and is normalised by `Chain::normalizeWalletAddress()` |
| **Key id** | the id the wallet registered, which its `jwks.json` lists. At the test wallet it is a **UUID**, not the wallet's name — a wrong id fails as `invalid_client: could not determine client`, which `assertKeyRegistered()` turns into a sentence. **None at a Dassie node**, see below |
| **Private key** | the Ed25519 key as **base64 of its PEM, on one line**. The PEM itself is refused with an instruction; that is what the SDK's signer expects |

**Two ways to be a client.** `Credentials::fromBase64Pem($keyId, $key)` is the client a wallet address stands for:
the wallet registered the key and lists it. `Credentials::keyOnly($key)` is a client known by its key alone, which
Open Payments calls directed identity: the public key travels in the grant request, nothing is registered, and the
key id is the key's RFC 7638 thumbprint. That is how a shop is a client of a **Dassie node**, whose `jwks.json`
lists the node's own key and nobody else's — any Ed25519 key will do, and the node grants creating and reading
incoming payments to it at once, without its owner. The test wallet takes a key-only client too. What such a
client cannot get is anything that needs the owner's consent, which the receiving side never asks for; at a
Dassie node it also shares the room the node keeps for clients its owner has not approved.

The asset is not configured: a wallet address holds exactly one, and says which. What the merchant decides is
whether that asset is accepted (`ConfigProviderInterface::isAssetEnabled('INTERLEDGER', $assetCode)`).

## How a payment is recorded

Schema v1 unchanged, with two fields read for what Interledger has instead of a ledger account:

| Field | Interledger |
|---|---|
| `chain` | `INTERLEDGER` |
| `type` | `interledger-<asset>-payment` |
| `base_asset` | the wallet address's `assetCode` |
| `destination_account` | **the incoming payment's URL, per order** — the payment link itself |
| `destination_tag` | the merchant's reference, carried in the incoming payment's `metadata.externalRef` as `LD-<reference>`; the customer never types it |
| `amount_requested` | `{currency: <assetCode>, value: <decimal>, issuer: <wallet address>}` — the account-holding institution is the issuer of an Interledger balance |
| `amount_paid` | `receivedAmount`, in the same shape |
| `hash` | the incoming payment's id, once something has arrived |
| `ctid` | `null` |
| `expiry` | the incoming payment's `expiresAt`, so both sides know the same deadline |

Everything downstream therefore works unchanged: `SettlementPolicy` judges an issued currency, `PaymentStatus`
derives `waiting` / `partial` / `settled`, and every adapter reads the record it already reads.

## The pieces

| Class | What it does |
|---|---|
| `Chain` | the chain as data: types per asset, wallet-address format, a `ChainDescriptor` for a configuration screen |
| `Client\OpenPaymentsInterface` | the merchant's own wallet, narrowed to four calls; `SdkOpenPaymentsClient` implements it over the SDK and handles the grant |
| `Client\Sdk\…` | the SDK's client with two requests put right, internal: the `Signature` header as the byte sequence RFC 9421 defines (`sig1=:…:`, the SDK leaves the colons out) and the grant request at the URL the wallet address names (the SDK appends a slash). Rafiki takes the SDK's forms, a Dassie node answers them with 401 and 404; both take the corrected ones |
| `IncomingPaymentService` | the quote part: `quoteForOrder()` creates the incoming payment for an order and returns the `PaymentIntent`; a quote that is still valid is handed back instead of costing a second resource |
| `ReceiptSyncService` | the fulfillment part: `findFulfillmentFor($intent)` reads an order's incoming payment and returns a `Receipt` whose `applyTo()` writes `amount_paid` and `hash` — the core's names, so an adapter's matching code does not branch. Implements `Sync\LedgerSyncInterface` with a `sync()` that does nothing: the wallet is asked in `findFulfillmentFor()`, so that is the call an adapter holds back with `SyncThrottle`, one mark per incoming payment |
| `ReferenceService` | the reference, from the core's shared identifier permutation |
| `PaymentRequest` | what the payment page shows: the incoming payment URL is already the QR code's payload |
| `ScaledAmount` | Open Payments counts in unscaled integers, the core in decimal strings — the conversion, exactly, in one place |
| `Port\InterledgerRepositoryInterface` | all the platform has to provide: a counter per wallet address (`Schema` has the one table) |
| `Testing\*` | an in-memory resource server, an in-memory repository, and the port's contract test |

`LedgerDirectInterledger::create()` wires it all up, next to the core's `LedgerDirect` and `LedgerDirectStellar`.

## Known gaps of the prototype

- **No top-up after expiry.** An expired incoming payment accepts nothing, so a partial payment can only be
  completed before the quote runs out. A new incoming payment for the shortfall is the way out, and is not built.
- **No fiat conversion.** Same code on both sides is a 1:1 peg; otherwise the core's oracles are asked, which
  covers XRP. A EUR wallet taking a USD order raises `NoPriceForAssetException`.
- **No settlement proof per payment.** Interledger settles between nodes in bundles, so there is no on-ledger
  transaction to link. The incoming payment URL is the evidence.
- **No browser wallet** on the payment page, and `wrong_asset` cannot occur by construction (an incoming payment
  has exactly one asset).
- **No request timeout.** The SDK builds its HTTP client inside the method that sends the request and takes no
  options, so a wallet that accepts a connection and then goes quiet holds the request until PHP's own limit.
  An adapter that holds `findFulfillmentFor()` back with the core's `SyncThrottle` keeps a status endpoint to
  one read per interval, and a failed read means "nothing has arrived yet", but the real fix is an option
  upstream.

## Tests

```bash
composer install
vendor/bin/phpunit
```

No network, no wallet, no test money: the services run against `Testing\FakeOpenPayments`, and the one class
that talks to the SDK is tested against the responses recorded from the test wallet and from a Dassie node,
with its request bodies checked against the Open Payments schemas the SDK ships. The key in the tests is the
one of RFC 8037, whose public key and thumbprint are published.

## Against a real wallet

`dev/round-trip.php` does one round trip by hand, both sides, without a shop: the merchant creates the
incoming payment, the customer quotes it, approves the outgoing payment in the browser (that grant is
the one Open Payments makes interactive), pays, and the merchant polls until it is completed.

```bash
php dev/round-trip.php merchant-request         # the incoming payment
php dev/round-trip.php customer-grant           # prints the URL to approve
php dev/round-trip.php customer-pay '<redirect>'  # the URL the browser landed on, in quotes
php dev/round-trip.php merchant-poll
```

It needs two accounts with developer keys at an Open Payments wallet, as
`LEDGERDIRECT_INTERLEDGER_TESTNET_{MERCHANT,CUSTOMER}_{WALLET_ADDRESS,KEY_ID,PRIVATE_KEY}` in the
environment. The responses under `tests/fixtures/` were recorded with it, scrubbed. It talks to the SDK
directly and therefore to Rafiki-based wallets only.

`dev/merchant-check.php` is the merchant's half through this package, against any wallet:

```bash
php dev/merchant-check.php https://<node ID>.dassiepay.net/pay/xrp     # a Dassie node: a key-only client
php dev/merchant-check.php https://ilp.interledger-test.dev/acme <key id>  # a client the wallet registered
```

It reads the wallet address, creates one small incoming payment and reads it back. The `dassie-*` fixtures
were recorded that way from a periphery node on Dassie 0.4.5, with the token and the payment's STREAM
secret replaced.

## Licence

MIT.
