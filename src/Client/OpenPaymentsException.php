<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Client;

use Hardcastle\LedgerDirect\Interledger\InterledgerException;
use Throwable;

/**
 * The merchant's own Open Payments servers answered with something this
 * package cannot work with: a grant refused, a resource gone, a signature
 * the authorization server rejected, a response in an unexpected shape.
 *
 * Every failure out of the SDK ends up here, so an adapter catches one
 * type instead of the SDK's dozen per-route exception classes — and so
 * that nothing from the SDK's `print_r` of a response body reaches a log
 * unexamined.
 */
final class OpenPaymentsException extends InterledgerException
{
    public static function from(string $what, Throwable $cause): self
    {
        return new self("Open Payments: {$what} failed — " . $cause->getMessage(), 0, $cause);
    }
}
