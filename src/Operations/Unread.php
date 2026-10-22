<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;

/** A statement the classifier does not know. */
final readonly class Unread implements Operation
{
    public function assess(Context $context): array
    {
        return [$context->unread('This statement could not be analyzed: check what it locks, and for how long, before it runs on a large table.')];
    }

    public function apply(Context $context): void {}
}
