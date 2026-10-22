<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class LockTable implements Operation
{
    public function __construct(
        public string $table,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of($context->lock(
            'lock-table',
            $this->table,
            "The migration locks {$this->table} explicitly: the queries that conflict with the lock wait until the migration's transaction ends.",
        ));
    }

    public function apply(Context $context): void {}
}
