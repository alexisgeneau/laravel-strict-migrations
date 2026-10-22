<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class DropTable implements Operation
{
    public function __construct(
        public string $table,
        public bool $ifExists = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->ifExists && ! $context->created($this->table) && ! $context->inspector->exists($this->table)) {
            return [];
        }

        return Findings::of($context->deploy(
            'drop-table',
            $this->table,
            "Dropping {$this->table} breaks the code that still queries it while the deployment runs, and its rows are gone for good.",
            implode("\n", [
                "Stop using {$this->table} first: remove its model, relations and queries, and deploy that.",
                'Drop the table in a later deployment, marking that migration:',
                '',
                "    return new #[SafetyAssured('{$this->table} unused since the previous release')] class extends Migration",
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->run->forget($this->table);
    }
}
