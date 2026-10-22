<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Findings\Finding;

/** What one statement, or one clause of an ALTER TABLE, does to the database. */
interface Operation
{
    /** @return list<Finding> */
    public function assess(Context $context): array;

    /** Records what the operation changes, for the statements that follow it. */
    public function apply(Context $context): void;
}
