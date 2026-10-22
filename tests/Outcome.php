<?php

declare(strict_types=1);

namespace StrictMigrations\Tests;

use Throwable;

/** How an artisan command ended: its exit code, or the exception it threw, and what it printed. */
final readonly class Outcome
{
    public string $output;

    public function __construct(
        public ?int $status,
        string $output,
        public ?Throwable $exception = null,
    ) {
        $this->output = (string) preg_replace('/\e\[[\d;]*m/', '', $output);
    }
}
