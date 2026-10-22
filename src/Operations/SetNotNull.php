<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

/** ALTER COLUMN ... SET NOT NULL on PostgreSQL. */
final readonly class SetNotNull implements Operation
{
    public function __construct(
        public string $table,
        public string $column,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->created($this->table) || $context->inspector->column($this->table, $this->column)?->nullable === false) {
            return [];
        }

        if ($context->server->atLeast('12') && $context->inspector->provesNotNull($this->table, $this->column)) {
            return [$context->note('not-null', "A validated check constraint already proves {$this->table}.{$this->column} holds no NULL: NOT NULL is set without scanning the table.")];
        }

        return Findings::of($context->lock(
            'not-null',
            $this->table,
            "Setting {$this->table}.{$this->column} NOT NULL scans every row of {$this->table} while blocking reads and writes.",
            $context->server->atLeast('12') ? $this->recipe($context) : 'Before PostgreSQL 12, setting NOT NULL always scans the table: run it in a maintenance window.',
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }

    private function recipe(Context $context): string
    {
        $table = $context->quote($this->table);
        $column = $context->quote($this->column);
        $constraint = $context->quote(substr((string) strrchr('.'.$this->table, '.'), 1)."_{$this->column}_not_null");

        return implode("\n", [
            'Prove it first with a check constraint, validated without blocking writes, in a migration that runs outside a transaction:',
            '',
            '    public $withinTransaction = false;',
            '',
            Php::statement("alter table {$table} add constraint {$constraint} check ({$column} is not null) not valid"),
            Php::statement("alter table {$table} validate constraint {$constraint}"),
            '',
            'NOT NULL is then set without scanning the table; drop the check constraint afterwards:',
            '',
            Php::statement("alter table {$table} alter column {$column} set not null"),
            Php::statement("alter table {$table} drop constraint {$constraint}"),
        ]);
    }
}
