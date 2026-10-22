<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

/** ADD CONSTRAINT ... UNIQUE on PostgreSQL, which builds its index unless given one. */
final readonly class AddUniqueConstraint implements Operation
{
    /** @param list<string> $columns */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public bool $usingIndex = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->usingIndex) {
            return [];
        }

        return Findings::of($context->lock(
            'unique-constraint',
            $this->table,
            "Adding the unique constraint {$this->name} builds its index while blocking reads and writes on {$this->table}.",
            implode("\n", [
                'Build the index concurrently, then attach it to the constraint, which is instant: ->online() does both, in a migration that runs outside a transaction.',
                '',
                '    public $withinTransaction = false;',
                '',
                '    Schema::table('.Php::string($context->blueprintTable($this->table)).', function (Blueprint $table) {',
                '        $table->unique('.Php::list($this->columns).')->online();',
                '    });',
            ]),
        ));
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}
