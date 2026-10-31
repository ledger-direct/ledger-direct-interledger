<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

/**
 * The prototype cannot price this order in the wallet's asset: the codes
 * differ and the core has no rate for the pair. Fiat-to-fiat conversion
 * is a known gap of the prototype (handover §7, item 7, and §8) — a
 * EUR wallet takes EUR orders, a USD wallet USD orders, and XRP is priced
 * through the core's oracles.
 */
final class NoPriceForAssetException extends InterledgerException
{
}
