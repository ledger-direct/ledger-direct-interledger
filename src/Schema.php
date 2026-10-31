<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Interledger;

/**
 * The one table this package needs, as data plus MySQL DDL — the same
 * shape the core's Xrpl\Schema and Stellar\Schema hand out, so an
 * adapter's migration stays the three lines it is for those chains.
 *
 * One table, and only a counter in it. Open Payments keeps the payments
 * themselves at the merchant's wallet and hands them back on request, so
 * there is nothing here to mirror — no transaction table, no cursor, no
 * dedup key. What cannot be derived from anywhere else is the reference
 * sequence, because it has to be strictly increasing per wallet address
 * and must survive an uninstall.
 */
final class Schema
{
    public const TABLE_REFERENCE = 'ledger_direct_interledger_reference';

    /**
     * @return array<string, array{
     *     columns: list<array{name: string, type: 'int'|'string', length?: int, unsigned?: bool, nullable: bool}>,
     *     primary: list<string>,
     * }>
     */
    public static function tables(): array
    {
        return [
            self::TABLE_REFERENCE => [
                'columns' => [
                    // A wallet address is a URL, so this column is wider than an account column
                    // on any ledger chain; 255 is the limit of what a key column can hold on
                    // utf8mb4 with MySQL's default index size.
                    ['name' => 'wallet_address', 'type' => 'string', 'length' => 255, 'nullable' => false],
                    ['name' => 'sequence', 'type' => 'int', 'unsigned' => true, 'nullable' => false],
                ],
                'primary' => ['wallet_address'],
            ],
        ];
    }

    /**
     * One `CREATE TABLE IF NOT EXISTS` per table, in tables() order.
     *
     * @return list<string>
     */
    public static function mysql(string $prefix = '', string $engine = 'InnoDB', string $charset = 'utf8mb4'): array
    {
        $statements = [];

        foreach (self::tables() as $table => $definition) {
            $lines = [];

            foreach ($definition['columns'] as $column) {
                $lines[] = '    `' . $column['name'] . '` ' . self::mysqlType($column);
            }

            $lines[] = '    PRIMARY KEY (' . implode(', ', array_map(
                static fn (string $c): string => '`' . $c . '`',
                $definition['primary'],
            )) . ')';

            $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $prefix . $table . "` (\n"
                . implode(",\n", $lines) . "\n"
                . ') ENGINE=' . $engine . ' DEFAULT CHARSET=' . $charset . ';';
        }

        return $statements;
    }

    /**
     * @param array{name: string, type: string, length?: int, unsigned?: bool, nullable: bool} $column
     */
    private static function mysqlType(array $column): string
    {
        $type = match ($column['type']) {
            'int' => 'INT',
            'string' => 'VARCHAR(' . $column['length'] . ')',
        };

        if ($column['unsigned'] ?? false) {
            $type .= ' UNSIGNED';
        }

        return $type . ($column['nullable'] ? ' NULL DEFAULT NULL' : ' NOT NULL');
    }
}
