<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use Closure;
use Throwable;

/** The statements a migration issued during its dry run, and what stopped it early, if anything did. */
final class Recording
{
    /** @var list<RecordedStatement> */
    private array $statements = [];

    private int $assured = 0;

    private ?Throwable $interruption = null;

    public function __construct(
        private readonly int $limit = 1_000,
    ) {}

    /**
     * @param  array<int|string, mixed>  $bindings
     *
     * @throws DryRunInterrupted when the migration issues more statements than the limit, which a
     *                           loop waiting for its own writes to take effect would do forever
     */
    public function record(string $sql, array $bindings = []): void
    {
        if (count($this->statements) >= $this->limit) {
            $this->interrupt($exception = new DryRunInterrupted("The analysis stopped after {$this->limit} statements: the rest of the migration was not analyzed."));

            throw $exception;
        }

        $this->statements[] = new RecordedStatement($sql, $bindings, $this->assured > 0);
    }

    /**
     * Runs the callback with the statements it issues marked as checked by the developer.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function assure(Closure $callback): mixed
    {
        $this->assured++;

        try {
            return $callback();
        } finally {
            $this->assured--;
        }
    }

    /** Records what stopped the analysis; the first cause is the one reported. */
    public function interrupt(Throwable $exception): void
    {
        $this->interruption ??= $exception;
    }

    /** @return list<RecordedStatement> */
    public function statements(): array
    {
        return $this->statements;
    }

    public function interruption(): ?Throwable
    {
        return $this->interruption;
    }
}
