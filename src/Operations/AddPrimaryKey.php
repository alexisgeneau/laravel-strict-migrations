<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Findings;

final readonly class AddPrimaryKey implements Operation
{
    /** @param list<string> $columns */
    public function __construct(
        public string $table,
        public array $columns,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->created($this->table)) {
            return [];
        }

        if ($context->engine()->isMySqlFamily()) {
            return [$context->note('primary-key', "{$this->table} is rebuilt in place to add its primary key: writes continue, and the statement fails on duplicate or NULL values.")];
        }

        if ($context->engine() !== Engine::Postgres) {
            return [];
        }

        $table = $context->quote($this->table);
        $index = $context->quote(substr((string) strrchr('.'.$this->table, '.'), 1).'_pkey');
        $columns = implode(', ', array_map($context->quote(...), $this->columns));

        return Findings::of($context->lock(
            'primary-key',
            $this->table,
            "Adding a primary key to {$this->table} builds its index while blocking reads and writes.",
            implode("\n", [
                'Build the index concurrently first, in a migration that runs outside a transaction, then attach it to the primary key, which is instant:',
                '',
                '    public $withinTransaction = false;',
                '',
                Php::statement("create unique index concurrently {$index} on {$table} ({$columns})"),
                Php::statement("alter table {$table} add constraint {$index} primary key using index {$index}"),
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
