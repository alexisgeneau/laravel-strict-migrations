<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

/** INSERT, UPDATE, DELETE, MERGE or REPLACE run by a migration. */
final readonly class DataChange implements Operation
{
    /**
     * @param  bool  $everyRow  Whether the statement has no WHERE clause.
     */
    public function __construct(
        public string $verb,
        public string $table,
        public bool $everyRow = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->verb === 'insert' || $this->table === 'sqlite_sequence') {
            return [];
        }

        if ($this->verb === 'delete' && $this->everyRow) {
            return Findings::of($context->destroys('data-change', $this->table, "Deleting from {$this->table} without a WHERE clause deletes every row it holds."));
        }

        $action = match ($this->verb) {
            'update' => "Updating {$this->table}",
            'delete' => "Deleting from {$this->table}",
            default => ucfirst($this->verb)." into {$this->table}",
        };

        if ($context->transactional && $context->altered($this->table)) {
            return Findings::of($context->lock(
                'schema-and-data',
                $this->table,
                "{$this->table} was altered earlier in this migration: its exclusive lock stays held until the migration commits, while the rows change.",
                'Change the schema and the rows in separate migrations: the schema change then commits, and releases its lock, before the rows change.',
            ));
        }

        return Findings::of($context->data(
            'data-change',
            $this->table,
            $context->transactional
                ? "{$action} in the migration's transaction keeps every row it changes locked until the migration commits."
                : "{$action} in a migration changes every matching row in one statement: they stay locked until it ends, and on a large table it runs long.",
            implode("\n", [
                'Change the rows in batches, in a migration of their own that runs outside a transaction:',
                '',
                '    public $withinTransaction = false;',
                '',
                '    DB::table('.Php::string($context->blueprintTable($this->table)).')->chunkById(1000, function ($rows) {',
                '        // change the rows of this batch',
                '    });',
                '',
                'Or in a queued job, or a command run after the deployment.',
            ]),
        ));
    }

    public function apply(Context $context): void {}
}
