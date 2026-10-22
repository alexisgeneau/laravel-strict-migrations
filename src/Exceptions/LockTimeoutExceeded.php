<?php

declare(strict_types=1);

namespace StrictMigrations\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface;

/** A migration that kept waiting for a lock another transaction holds. */
final class LockTimeoutExceeded extends RuntimeException implements ExceptionInterface
{
    public static function after(string $migration, int $seconds, int $attempts, bool $retryable, QueryException $previous): self
    {
        return new self(
            $retryable
                ? sprintf('%s could not take its locks within %d s, %d %s: another transaction holds them. Nothing of the migration was applied; run the migrations again once that transaction has ended.', $migration, $seconds, $attempts, $attempts === 1 ? 'attempt' : 'attempts')
                : sprintf('%s could not take its locks within %d s: another transaction holds them. Statements of the migration had already run outside a transaction, so it was not retried: check what it applied before running it again.', $migration, $seconds),
            0,
            $previous,
        );
    }
}
