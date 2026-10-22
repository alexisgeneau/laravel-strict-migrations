<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Findings;

final readonly class AddCheck implements Operation
{
    public function __construct(
        public string $table,
        public string $name,
        public string $expression,
        public bool $notValid = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->engine() === Engine::Sqlite || $this->notValid) {
            return [];
        }

        if ($context->engine()->isMySqlFamily()) {
            return Findings::of($context->lock(
                'check-constraint',
                $this->table,
                "Adding the check constraint {$this->name} copies {$this->table} while blocking writes.",
                'MySQL cannot add it without copying the table: run it in a maintenance window, or through gh-ost or pt-online-schema-change.',
            ));
        }

        $table = $context->quote($this->table);
        $name = $context->quote($this->name);

        return Findings::of($context->lock(
            'check-constraint',
            $this->table,
            "Adding the check constraint {$this->name} scans every row of {$this->table} while blocking reads and writes.",
            implode("\n", [
                'Add it without checking the existing rows, which is instant, then validate it, which does not block writes, in a migration that runs outside a transaction:',
                '',
                '    public $withinTransaction = false;',
                '',
                Php::statement("alter table {$table} add constraint {$name} check ({$this->expression}) not valid"),
                Php::statement("alter table {$table} validate constraint {$name}"),
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
