<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

/** DROP PRIMARY KEY on MySQL and MariaDB, without a new primary key in the same statement. */
final readonly class DropPrimaryKey implements Operation
{
    public function __construct(
        public string $table,
        public AlterOptions $options = new AlterOptions,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->options->keepsWrites()) {
            return [];
        }

        return Findings::of($context->lock(
            'primary-key',
            $this->table,
            "Dropping the primary key of {$this->table} copies the table while blocking writes.",
            'Drop the old primary key and add the new one in the same statement: the table is then rebuilt in place, without blocking writes.',
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
