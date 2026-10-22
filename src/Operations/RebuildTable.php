<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Findings;

/**
 * A statement that rewrites the whole table: a character set conversion on MySQL, SET LOGGED
 * or CLUSTER on PostgreSQL, or the copy Laravel makes of a table to alter it on SQLite.
 */
final readonly class RebuildTable implements Operation
{
    /** @param string $what  What rebuilds the table, as the message names it. */
    public function __construct(
        public string $table,
        public string $what,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->created($this->table)) {
            return [];
        }

        if ($context->engine() === Engine::Sqlite) {
            return [$context->note('table-rebuild', "SQLite rebuilds {$this->table} to alter it: the database is locked for writers while its rows are copied.")];
        }

        $blocks = $context->engine() === Engine::Postgres ? 'reads and writes' : 'writes';

        return Findings::of($context->lock(
            'table-rebuild',
            $this->table,
            ucfirst("{$this->what} rewrites {$this->table} while blocking {$blocks}."),
            $context->engine() === Engine::Postgres
                ? 'Run it in a maintenance window: there is no way to do it without blocking the table.'
                : 'Run it in a maintenance window, or through gh-ost or pt-online-schema-change, which copy the table without blocking writes.',
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
