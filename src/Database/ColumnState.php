<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

/** A column as it stands in the database before the migration runs. */
final readonly class ColumnState
{
    public function __construct(
        public string $type,
        public bool $nullable,
        public ?string $collation = null,
        public bool $indexed = false,
    ) {}
}
