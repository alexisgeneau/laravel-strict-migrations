<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;

final readonly class DropIndex implements Operation
{
    public function __construct(
        public string $name,
        public ?string $table = null,
        public bool $concurrently = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->engine() !== Engine::Postgres || ($this->table !== null && $context->created($this->table))) {
            return [];
        }

        if ($this->concurrently) {
            return $context->transactional
                ? [$context->error('index-in-transaction', 'drop index concurrently cannot run inside a transaction: the migration would fail.', "Run the migration outside a transaction:\n\n    public \$withinTransaction = false;")]
                : [];
        }

        return [$context->warn(
            'drop-index',
            "Dropping {$this->name} takes an exclusive lock on ".($this->table ?? 'its table').': it waits for the queries running there, and the queries that come after wait behind it.',
            implode("\n", [
                'Drop it concurrently, in a migration that runs outside a transaction:',
                '',
                '    public $withinTransaction = false;',
                '',
                Php::statement("drop index concurrently if exists {$context->quote($this->name)}"),
            ]),
        )];
    }

    public function apply(Context $context): void
    {
        if ($this->table !== null) {
            $context->alter($this->table);
        }
    }
}
