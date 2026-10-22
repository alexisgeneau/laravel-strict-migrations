<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Findings;

/** VACUUM, REINDEX and REFRESH MATERIALIZED VIEW on PostgreSQL. */
final readonly class Maintenance implements Operation
{
    /**
     * @param  string  $command  vacuum, reindex or refresh
     * @param  string|null  $target  The table, index or view, when the statement names one.
     */
    public function __construct(
        public string $command,
        public ?string $target,
        public bool $concurrently = false,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->command !== 'refresh' && $context->transactional && ($this->command === 'vacuum' || $this->concurrently)) {
            $statement = $this->command === 'vacuum' ? 'vacuum' : 'reindex concurrently';

            return [$context->error('maintenance-in-transaction', "{$statement} cannot run inside a transaction: the migration would fail.", "Run the migration outside a transaction:\n\n    public \$withinTransaction = false;")];
        }

        if ($this->concurrently || $this->command === 'vacuum' || $this->target === null) {
            return [];
        }

        return Findings::of($this->command === 'reindex'
            ? $context->lock(
                'reindex',
                $this->target,
                "Reindexing {$this->target} blocks writes to its table until the indexes are rebuilt.",
                "Reindex concurrently (PostgreSQL 12+), in a migration that runs outside a transaction:\n\n    public \$withinTransaction = false;",
            )
            : $context->lock(
                'refresh-materialized-view',
                $this->target,
                "Refreshing {$this->target} blocks every read of the view until it is refreshed.",
                'Refresh it concurrently, which keeps it readable: the view needs a unique index for that.',
            ),
        );
    }

    public function apply(Context $context): void {}
}
