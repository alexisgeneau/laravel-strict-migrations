<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;

/** A SET statement: only foreign_key_checks changes what the statements after it do. */
final readonly class SessionSetting implements Operation
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}

    public function assess(Context $context): array
    {
        return [];
    }

    public function apply(Context $context): void
    {
        if ($this->name === 'foreign_key_checks') {
            $context->checkForeignKeys(in_array(strtolower(trim($this->value, '\'" ')), ['1', 'on', 'true'], true));
        }
    }
}
