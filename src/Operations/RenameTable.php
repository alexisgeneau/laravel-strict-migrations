<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

final readonly class RenameTable implements Operation
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of($context->deploy(
            'rename-table',
            $this->from,
            "Renaming {$this->from} to {$this->to} breaks the code that still uses {$this->from} while the deployment runs.",
            implode("\n", [
                'Leave a view under the old name until no code uses it: simple views can be read and written through.',
                '',
                Php::statement("alter table {$context->quote($this->from)} rename to {$context->quote($this->to)}"),
                Php::statement("create view {$context->quote($this->from)} as select * from {$context->quote($this->to)}"),
                '',
                'Drop the view in a later deployment.',
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->run->rename($this->from, $this->to);
    }
}
