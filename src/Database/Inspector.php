<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * What the rules need to know about the database as it stands: how many rows a table holds,
 * up to the threshold that matters, and the current definition of a column. Every answer is
 * cached for the analysis, and a question the database cannot answer gets the cautious answer.
 */
final class Inspector
{
    /** @var array<string, int|null> */
    private array $rows = [];

    /** @var array<string, array<string, ColumnState>> */
    private array $columns = [];

    private ?bool $foreignKeyChecks = null;

    private ?bool $utc = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Server $server,
        private readonly bool $measures,
        private readonly int $limit,
    ) {}

    public function measures(): bool
    {
        return $this->measures;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** The rows of a table counted up to the limit plus one, or null when not measured or unreadable. */
    public function rows(string $table): ?int
    {
        if (! $this->measures) {
            return null;
        }

        if (! array_key_exists($table, $this->rows)) {
            $this->rows[$table] = $this->ask(fn (): int => (int) $this->connection->scalar(
                sprintf('select count(*) from (select 1 from %s limit %d) as bounded', $this->quote($table), $this->limit + 1),
                [],
                false,
            ));
        }

        return $this->rows[$table];
    }

    /** Whether the table exists; without measurements every table is assumed to. */
    public function exists(string $table): bool
    {
        if (! $this->measures) {
            return true;
        }

        return $this->ask(fn (): bool => $this->connection->getSchemaBuilder()->hasTable($this->unprefixed($table))) ?? true;
    }

    public function column(string $table, string $column): ?ColumnState
    {
        if (! array_key_exists($table, $this->columns)) {
            $this->columns[$table] = $this->ask(function () use ($table): array {
                $schema = $this->connection->getSchemaBuilder();
                $indexed = array_merge([], ...array_column($schema->getIndexes($this->unprefixed($table)), 'columns'));
                $states = [];

                foreach ($schema->getColumns($this->unprefixed($table)) as $definition) {
                    $states[$definition['name']] = new ColumnState(
                        strtolower($definition['type']),
                        $definition['nullable'],
                        $definition['collation'] ?? null,
                        in_array($definition['name'], $indexed, true),
                    );
                }

                return $states;
            }) ?? [];
        }

        return $this->columns[$table][$column] ?? null;
    }

    /** Whether a validated CHECK constraint already proves the column holds no NULL (PostgreSQL 12+). */
    public function provesNotNull(string $table, string $column): bool
    {
        if ($this->server->engine !== Engine::Postgres) {
            return false;
        }

        $definitions = $this->ask(fn (): array => array_column(array_map(
            fn (mixed $row): array => (array) $row,
            $this->connection->selectFromWriteConnection(
                "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = to_regclass(?) and contype = 'c' and convalidated",
                [$this->quote($table)],
            ),
        ), 'definition')) ?? [];

        foreach ($definitions as $definition) {
            if (preg_match('/^CHECK \(+"?(.+?)"? IS NOT NULL\)+$/i', $definition, $match) && $match[1] === $column) {
                return true;
            }
        }

        return false;
    }

    /** Whether MySQL checks the foreign keys of this session (a new constraint is then validated by copying the table). */
    public function foreignKeyChecks(): bool
    {
        return $this->foreignKeyChecks ??= (bool) ($this->ask(fn (): bool => (bool) $this->connection->scalar('select @@foreign_key_checks', [], false)) ?? true);
    }

    /** Whether the PostgreSQL session runs in UTC, which lets timestamp and timestamptz convert without a rewrite. */
    public function utcSession(): bool
    {
        return $this->utc ??= in_array(
            $this->ask(fn (): string => (string) $this->connection->scalar('show timezone', [], false)),
            ['UTC', 'Etc/UTC', 'GMT', 'Etc/GMT', 'Universal', 'Zulu', 'Etc/Universal', 'Etc/Zulu'],
            true,
        );
    }

    /**
     * Runs a read that may fail, inside a savepoint when a transaction is open so that a
     * failure on PostgreSQL does not abort it.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T|null
     */
    private function ask(Closure $read): mixed
    {
        try {
            return $this->connection->transactionLevel() > 0 ? $this->connection->transaction($read) : $read();
        } catch (QueryException) {
            return null;
        }
    }

    /** The name the schema builder expects for a table: without the connection's prefix, which it adds back. */
    private function unprefixed(string $table): string
    {
        $prefix = $this->connection->getTablePrefix();
        $segments = explode('.', $table);
        $name = (string) array_pop($segments);

        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        return implode('.', [...$segments, $name]);
    }

    private function quote(string $table): string
    {
        $quote = $this->server->engine->isMySqlFamily() ? '`' : '"';

        return implode('.', array_map(
            fn (string $segment): string => $quote.str_replace($quote, $quote.$quote, $segment).$quote,
            explode('.', $table),
        ));
    }
}
