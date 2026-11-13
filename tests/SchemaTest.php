<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger\Tests;

use Hardcastle\LedgerDirect\Interledger\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testThePackageNeedsOneTableAndItHoldsOnlyACounter(): void
    {
        $tables = Schema::tables();

        self::assertSame(['ledger_direct_interledger_reference'], array_keys($tables));
        self::assertSame(
            ['wallet_address', 'sequence'],
            array_column($tables[Schema::TABLE_REFERENCE]['columns'], 'name'),
        );
        self::assertSame(['wallet_address'], $tables[Schema::TABLE_REFERENCE]['primary']);
    }

    public function testTheDdlIsIdempotentAndPrefixable(): void
    {
        $statements = Schema::mysql('sw6_');

        self::assertCount(1, $statements);
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS `sw6_ledger_direct_interledger_reference`',
            $statements[0],
        );
        self::assertStringContainsString('`wallet_address` VARCHAR(255) NOT NULL', $statements[0]);
        self::assertStringContainsString('`sequence` INT UNSIGNED NOT NULL', $statements[0]);
        self::assertStringContainsString('PRIMARY KEY (`wallet_address`)', $statements[0]);
    }
}
