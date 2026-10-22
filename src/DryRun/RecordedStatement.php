<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

final readonly class RecordedStatement
{
    /** @param array<int|string, mixed> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings = [],
        public bool $assured = false,
    ) {}
}
