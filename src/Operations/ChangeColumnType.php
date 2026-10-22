<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\PostgresTypes;
use StrictMigrations\Findings\Findings;

/** ALTER COLUMN ... TYPE on PostgreSQL, which ->change() emits even when the type stays the same. */
final readonly class ChangeColumnType implements Operation
{
    public function __construct(
        public string $table,
        public string $column,
        public string $type,
        public bool $using = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->created($this->table)) {
            return [];
        }

        $current = $context->inspector->column($this->table, $this->column);

        if ($current !== null && ! $this->using && ! PostgresTypes::rewrites($current->type, $this->type, $context->inspector->utcSession())) {
            return [];
        }

        $from = $current === null ? 'its current type' : PostgresTypes::canonical($current->type);

        return Findings::of($context->lock(
            'column-type',
            $this->table,
            sprintf('Changing %s.%s from %s to %s rewrites %s and its indexes while blocking reads and writes.', $this->table, $this->column, $from, PostgresTypes::canonical($this->type), $this->table),
            implode("\n", [
                'Change it over several deployments:',
                '1. add a column of the new type, and write to both;',
                '2. fill it for the existing rows in batches, outside the migration\'s transaction;',
                '3. switch the code to the new column, then drop the old one.',
                '',
                'A longer varchar, varchar to text, or a numeric with a larger precision change without a rewrite.',
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
