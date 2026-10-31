<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;
use Hardcastle\LedgerDirect\Interledger\Testing\InMemoryInterledgerRepository;
use Hardcastle\LedgerDirect\Interledger\Testing\InterledgerRepositoryContractTestCase;

/**
 * The reference implementation against its own contract — the same suite
 * an adapter's database repository has to pass.
 */
final class InMemoryInterledgerRepositoryTest extends InterledgerRepositoryContractTestCase
{
    protected function repository(): InterledgerRepositoryInterface
    {
        return new InMemoryInterledgerRepository();
    }
}
