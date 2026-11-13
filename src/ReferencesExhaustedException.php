<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

/**
 * The reference counter for a wallet address has handed out every value
 * in the shared identifier range — the sibling of the XRPL's
 * DestinationTagsExhaustedException, reached after 2^32 - 10000 orders.
 */
final class ReferencesExhaustedException extends InterledgerException
{
}
