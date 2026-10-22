<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class DropColumn implements Operation
{
    public function __construct(
        public string $table,
        public string $column,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of($context->deploy(
            'drop-column',
            $this->table,
            "Dropping {$this->table}.{$this->column} breaks the code that still reads or writes it while the deployment runs.",
            implode("\n", [
                "Stop using {$this->column} first: remove it from \$fillable, \$casts, queries, resources and factories, and deploy that.",
                'Drop the column in a later deployment, marking that migration:',
                '',
                "    return new #[SafetyAssured('{$this->column} unused since the previous release')] class extends Migration",
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
