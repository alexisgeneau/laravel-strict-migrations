<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Intent;
use Throwable;
use WeakMap;

/**
 * Runs a migration without letting it change anything: its connection talks to a recording
 * PDO for as long as the migration runs, and any statement that would still reach a database,
 * through another connection or after a reconnection, stops the dry run before it executes.
 */
final class DryRun
{
    private ?Recording $recording = null;

    private ?Connection $connection = null;

    private ?RecordingPdo $pdo = null;

    /** @var WeakMap<Connection, true> */
    private WeakMap $guarded;

    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly SideEffects $sideEffects,
    ) {
        $this->guarded = new WeakMap;
    }

    /** @param Closure(): mixed $migration */
    public function record(Connection $connection, Dialect $dialect, Closure $migration): Recording
    {
        foreach ($this->databases->getConnections() as $other) {
            $this->guard($other);
        }

        $this->guard($connection);

        $real = $connection->getPdo();
        $recording = $this->recording = new Recording;
        $this->connection = $connection;
        $this->pdo = new RecordingPdo($real, $recording, $dialect);
        $this->swap($connection, $this->pdo);

        try {
            $this->sideEffects->withheld($migration);
        } catch (Throwable $exception) {
            $recording->interrupt($exception);
        } finally {
            $this->swap($connection, $real);
            $this->recording = $this->connection = $this->pdo = null;
        }

        return $recording;
    }

    /**
     * Runs the callback with its statements marked as checked when a dry run records them,
     * and simply runs it otherwise. Schema::safetyAssured() calls this.
     *
     * @param  Closure(): mixed  $callback
     */
    public function assured(Closure $callback): mixed
    {
        return $this->recording === null ? $callback() : $this->recording->assure($callback);
    }

    /** Registers, once per connection, the check that keeps a dry run from reaching a database. */
    public function guard(Connection $connection): void
    {
        if (isset($this->guarded[$connection])) {
            return;
        }

        $this->guarded[$connection] = true;

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if ($this->recording === null) {
                return;
            }

            if ($connection === $this->connection) {
                if ($connection->getRawPdo() !== $this->pdo) {
                    $this->interrupt('The connection was replaced during the analysis, by a reconnection: the rest of the migration was not analyzed.');
                }

                return;
            }

            if (Intent::of($query, Dialect::forDriver($connection->getDriverName())) !== Intent::Read) {
                $this->interrupt("The migration writes through the connection [{$connection->getName()}], which the analysis does not follow: the rest of the migration was not analyzed.");
            }
        });
    }

    /**
     * Stops the dry run before a statement would reach a database, and records why, in case
     * the migration catches the exception.
     *
     * @throws DryRunInterrupted
     */
    private function interrupt(string $reason): never
    {
        $exception = new DryRunInterrupted($reason);
        $this->recording?->interrupt($exception);

        throw $exception;
    }

    /**
     * Points the connection at another PDO. setPdo() would also reset the transaction level the
     * connection keeps, which an enclosing transaction depends on.
     */
    private function swap(Connection $connection, PDO $pdo): void
    {
        (function () use ($pdo): void {
            $this->pdo = $pdo;
        })->call($connection);
    }
}
