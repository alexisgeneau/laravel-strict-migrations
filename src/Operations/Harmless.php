<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;

/** A statement that locks no table for longer than an instant: a view, a function, an extension, a comment, a grant. */
final readonly class Harmless implements Operation
{
    public function assess(Context $context): array
    {
        return [];
    }

    public function apply(Context $context): void {}
}
