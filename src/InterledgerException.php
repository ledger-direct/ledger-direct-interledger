<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\LedgerDirectException;
use RuntimeException;

/**
 * Every runtime failure this package raises: the wallet address or the
 * resource server answering with an error, a key the wallet does not
 * know, a price the prototype cannot work out. Implements the core's
 * marker, so a platform's single `catch (LedgerDirectException)` covers
 * Interledger too.
 */
class InterledgerException extends RuntimeException implements LedgerDirectException
{
}
