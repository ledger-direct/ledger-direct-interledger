<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;

/**
 * Hands out the reference that ties an incoming payment to an order — the
 * counterpart of the XRPL's DestinationTagService and Stellar's
 * MemoIdService, over the same permutation and the same range.
 *
 * What differs is who reads it. On a ledger the customer has to put the
 * identifier on their payment, and getting it wrong loses the money. Here
 * the reference travels in the incoming payment's `metadata`, written by
 * the shop: the customer never sees it, and the merchant finds it in
 * their wallet activity next to the amount. It is kept anyway, and kept
 * in the same range as the other chains, because it is what makes a
 * refreshed quote recognisable — a new incoming payment for the same
 * order carries the same reference (Lesart A).
 */
final class ReferenceService
{
    /** The prefix that makes a reference recognisable in a wallet's activity list. */
    public const EXTERNAL_REF_PREFIX = 'LD-';

    public function __construct(
        private readonly InterledgerRepositoryInterface $repository,
    ) {
    }

    public function generateReference(string $walletAddress): int
    {
        $sequence = $this->repository->nextReferenceSequence($walletAddress);

        if (SequencePermutation::isExhausted($sequence)) {
            throw new ReferencesExhaustedException(
                "Wallet address {$walletAddress} has issued all " . SequencePermutation::RANGE_SIZE
                . ' available references.'
            );
        }

        return SequencePermutation::map($sequence);
    }

    /** The reference as the incoming payment's `externalRef` carries it. */
    public static function externalRef(int $reference): string
    {
        return self::EXTERNAL_REF_PREFIX . $reference;
    }

    /** The reference back out of an `externalRef`, or null when it is not one of ours. */
    public static function referenceFrom(string $externalRef): ?int
    {
        if (preg_match('/^' . preg_quote(self::EXTERNAL_REF_PREFIX, '/') . '([0-9]{1,10})$/D', $externalRef, $matches) !== 1) {
            return null;
        }

        $reference = (int) $matches[1];

        return $reference >= SequencePermutation::RANGE_MIN && $reference <= SequencePermutation::RANGE_MAX
            ? $reference
            : null;
    }
}
