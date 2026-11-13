<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Testing;

use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The contract every InterledgerRepositoryInterface implementation has to
 * keep, as a test an adapter extends — the sibling of the core's two
 * repository contract tests. A counter that starts at a random offset and
 * never at 0, strictly increasing, and separate per wallet address.
 *
 * The core's InMemoryInterledgerRepository passes this suite; so must a
 * platform's database implementation, which is the point: the random
 * start and the atomicity are what keep two shops on one wallet address
 * from issuing each other's references, and neither is visible from the
 * calling code.
 */
abstract class InterledgerRepositoryContractTestCase extends TestCase
{
    /** A fresh, empty repository for each test. */
    abstract protected function repository(): InterledgerRepositoryInterface;

    public function testTheCounterIncreasesByOnePerCall(): void
    {
        $repository = $this->repository();

        $first = $repository->nextReferenceSequence('https://ilp.example.test/merchant');
        $second = $repository->nextReferenceSequence('https://ilp.example.test/merchant');
        $third = $repository->nextReferenceSequence('https://ilp.example.test/merchant');

        self::assertSame($first + 1, $second);
        self::assertSame($first + 2, $third);
    }

    public function testTheCounterDoesNotStartAtZero(): void
    {
        $repository = $this->repository();

        self::assertGreaterThan(0, $repository->nextReferenceSequence('https://ilp.example.test/merchant'));
    }

    public function testTheCounterStartsSomewhereRandom(): void
    {
        $starts = [];

        for ($i = 0; $i < 5; ++$i) {
            $starts[] = $this->repository()->nextReferenceSequence('https://ilp.example.test/merchant');
        }

        self::assertGreaterThan(1, count(array_unique($starts)), 'five fresh counters all started at the same value');
    }

    public function testEachWalletAddressHasItsOwnCounter(): void
    {
        $repository = $this->repository();

        $one = $repository->nextReferenceSequence('https://ilp.example.test/merchant');
        $repository->nextReferenceSequence('https://ilp.example.test/other');
        $repository->nextReferenceSequence('https://ilp.example.test/other');

        self::assertSame($one + 1, $repository->nextReferenceSequence('https://ilp.example.test/merchant'));
    }
}
