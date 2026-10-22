<?php

declare(strict_types=1);

namespace StrictMigrations\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Severity;
use Symfony\Component\Console\Exception\ExceptionInterface;

/**
 * A migration refused before any of its statements ran. The console renders it without a
 * trace, under the report that explains it; without a report, the message holds the findings.
 */
final class UnsafeMigration extends RuntimeException implements ExceptionInterface, ShouldntReport
{
    public function __construct(
        public readonly Analysis $analysis,
        bool $reported = false,
    ) {
        $message = "{$analysis->migration} was refused before any of its statements ran.";

        if (! $reported) {
            $message .= ' '.implode(' ', array_map(fn (Finding $finding): string => $finding->message, $analysis->with(Severity::Unsafe)));
        }

        parent::__construct($message);
    }
}
