<?php

declare(strict_types=1);

namespace StrictMigrations\Attributes;

use Attribute;

/**
 * Marks a migration as checked: it runs without analysis, and the reason is printed instead.
 *
 *     return new #[SafetyAssured('orders holds 300 rows in production')] class extends Migration
 *     {
 *         ...
 *     };
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SafetyAssured
{
    public function __construct(
        public string $reason,
    ) {}
}
