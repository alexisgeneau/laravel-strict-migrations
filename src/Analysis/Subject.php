<?php

declare(strict_types=1);

namespace StrictMigrations\Analysis;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;

/** A migration about to run one of its methods on a connection. */
final readonly class Subject
{
    /**
     * @param  bool  $transactional  Whether the migrator wraps the method in a transaction, as it does on PostgreSQL.
     * @param  Closure(): mixed  $run  Runs the method the way the migrator does, on that connection.
     */
    public function __construct(
        public string $name,
        public string $file,
        public Migration $migration,
        public string $method,
        public Connection $connection,
        public bool $transactional,
        public Closure $run,
    ) {}
}
