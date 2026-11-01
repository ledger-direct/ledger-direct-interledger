<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Core\Identity\SequencePermutation;
use Hardcastle\LedgerDirect\Interledger\ReferenceService;
use Hardcastle\LedgerDirect\Interledger\ReferencesExhaustedException;
use Hardcastle\LedgerDirect\Interledger\Testing\InMemoryInterledgerRepository;
use PHPUnit\Framework\TestCase;

final class ReferenceServiceTest extends TestCase
{
    private const WALLET = 'https://ilp.example.test/merchant';

    public function testTheReferenceIsThePermutationOfTheCountersValue(): void
    {
        $repository = new InMemoryInterledgerRepository();
        $repository->scriptSequences(self::WALLET, [0, 1, 2]);
        $service = new ReferenceService($repository);

        self::assertSame(SequencePermutation::map(0), $service->generateReference(self::WALLET));
        self::assertSame(SequencePermutation::map(1), $service->generateReference(self::WALLET));
        self::assertSame(SequencePermutation::map(2), $service->generateReference(self::WALLET));
    }

    public function testReferencesStayInTheSharedIdentifierRange(): void
    {
        $service = new ReferenceService(new InMemoryInterledgerRepository());

        for ($i = 0; $i < 20; ++$i) {
            $reference = $service->generateReference(self::WALLET);

            self::assertGreaterThanOrEqual(SequencePermutation::RANGE_MIN, $reference);
            self::assertLessThanOrEqual(SequencePermutation::RANGE_MAX, $reference);
        }
    }

    public function testAnExhaustedCounterIsReportedAndNotWrappedAround(): void
    {
        $repository = new InMemoryInterledgerRepository();
        $repository->scriptSequences(self::WALLET, [SequencePermutation::RANGE_SIZE]);

        $this->expectException(ReferencesExhaustedException::class);

        (new ReferenceService($repository))->generateReference(self::WALLET);
    }

    public function testTheExternalRefCarriesTheReferenceAndReadsBack(): void
    {
        self::assertSame('LD-494349', ReferenceService::externalRef(494349));
        self::assertSame(494349, ReferenceService::referenceFrom('LD-494349'));
    }

    public function testSomethingElseInTheExternalRefIsNotOurReference(): void
    {
        self::assertNull(ReferenceService::referenceFrom('order-10001'));
        self::assertNull(ReferenceService::referenceFrom('LD-'));
        self::assertNull(ReferenceService::referenceFrom('LD-42'), 'below the range, so not one of ours');
        self::assertNull(ReferenceService::referenceFrom('LD-99999999999'));
        self::assertNull(ReferenceService::referenceFrom(' LD-494349'));
    }
}
