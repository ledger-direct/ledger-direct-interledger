<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Port;

/**
 * Provided by the platform, and deliberately tiny: a counter per wallet
 * address. Everything else an Interledger payment consists of lives
 * somewhere else already — the incoming payments at the resource server,
 * the PaymentIntent in the shop's own order record. There is no
 * `ledger_direct_interledger_tx` table, because there are no transactions
 * to store: Open Payments has no per-payment ledger event to mirror.
 *
 * An adapter that already implements the core's XRPL or Stellar ports can
 * satisfy this one with the same counter table (a different account value
 * in the same column), which is why the contract below is the XRPL port's,
 * verbatim in substance.
 */
interface InterledgerRepositoryInterface
{
    /**
     * Returns a number that is unique and strictly increasing per
     * $walletAddress; ReferenceService turns it into the customer-facing
     * reference through Identity\SequencePermutation — the same
     * permutation the XRPL's destination tags and Stellar's memo ids use.
     *
     * The first value for a wallet address MUST be a fresh
     * `random_int(0, 2 ** 31 - 1)`, drawn once when the counter row is
     * created; every later call returns the previous value plus one. It
     * MUST NOT start at 0, MUST be atomic under concurrent calls for the
     * same address, and the counter MUST survive plugin uninstall.
     *
     * The random start is not decoration: two installations sharing one
     * wallet address are two counters, and starting both at 0 makes them
     * walk the same sequence and issue each other's references.
     */
    public function nextReferenceSequence(string $walletAddress): int;
}
