<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class TruncateTable implements Operation
{
    public function __construct(
        public string $table,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of($context->destroys(
            'truncate',
            $this->table,
            "Truncating {$this->table} deletes every row it holds, and takes an exclusive lock that waits for the queries running on it.",
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
