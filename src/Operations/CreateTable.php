<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;

final readonly class CreateTable implements Operation
{
    /** @param list<string> $jsonColumns */
    public function __construct(
        public string $table,
        public bool $temporary = false,
        public array $jsonColumns = [],
    ) {}

    public function assess(Context $context): array
    {
        if ($context->engine() !== Engine::Postgres) {
            return [];
        }

        return array_map(
            fn (string $column) => $context->note('json', "{$this->table}.{$column} is json: jsonb can be indexed and compared, json cannot.", '    $table->jsonb('.Php::string($column).');'),
            $this->jsonColumns,
        );
    }

    public function apply(Context $context): void
    {
        if (! $this->temporary) {
            $context->run->create($this->table);
        }
    }
}
