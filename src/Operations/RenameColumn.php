<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class RenameColumn implements Operation
{
    public function __construct(
        public string $table,
        public string $from,
        public string $to,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of($context->deploy(
            'rename-column',
            $this->table,
            "Renaming {$this->table}.{$this->from} to {$this->to} breaks the code that still uses {$this->from} while the deployment runs.",
            implode("\n", [
                'Rename it over three deployments:',
                "1. add {$this->to}, write to both columns, and fill {$this->to} for the existing rows in batches;",
                "2. read from {$this->to} only;",
                "3. drop {$this->from}.",
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
