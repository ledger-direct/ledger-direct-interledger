<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Testing;

use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;

/**
 * In-memory InterledgerRepositoryInterface — the reference implementation
 * of the port contract, and the double an adapter's service tests use
 * instead of a database.
 */
final class InMemoryInterledgerRepository implements InterledgerRepositoryInterface
{
    private const MAX_RANDOM_START = 2147483647;

    /** @var array<string, int> */
    private array $sequences = [];

    /** @var array<string, list<int>> */
    private array $scriptedSequences = [];

    public function nextReferenceSequence(string $walletAddress): int
    {
        if (!empty($this->scriptedSequences[$walletAddress] ?? [])) {
            return array_shift($this->scriptedSequences[$walletAddress]);
        }

        if (!isset($this->sequences[$walletAddress])) {
            $this->sequences[$walletAddress] = random_int(0, self::MAX_RANDOM_START);
        }

        return $this->sequences[$walletAddress]++;
    }

    /**
     * Test control: the next calls for $walletAddress return these
     * values, in order, before the real counter takes over.
     *
     * @param list<int> $sequences
     */
    public function scriptSequences(string $walletAddress, array $sequences): void
    {
        $this->scriptedSequences[$walletAddress] = $sequences;
    }
}
