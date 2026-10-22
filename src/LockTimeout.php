<?php

declare(strict_types=1);

namespace StrictMigrations;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use StrictMigrations\Database\Engine;
use StrictMigrations\Database\Server;
use StrictMigrations\Exceptions\LockTimeoutExceeded;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Intent;
use StrictMigrations\Sql\Tokens;
use Symfony\Component\Console\Output\OutputInterface;
use WeakMap;

/**
 * Bounds how long a migration waits for a lock. A DDL statement queued behind a long
 * transaction makes every query that comes after it wait too, reads included: a short lock
 * timeout turns that outage into a failed attempt, retried when nothing of it was applied.
 */
final class LockTimeout
{
    private ?Connection $watched = null;

    private bool $applied = false;

    /** @var WeakMap<Connection, true> */
    private WeakMap $hooked;

    public function __construct(
        private readonly Guard $guard,
        Dispatcher $events,
    ) {
        $this->hooked = new WeakMap;

        $events->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if ($query->connection === $this->watched && ! $this->applied && preg_match('/^\s*set\b/i', $query->sql) !== 1
                && Intent::of($query->sql, Dialect::forDriver($query->connection->getDriverName())) === Intent::Write) {
                $this->applied = true;
            }
        });
    }

    /** @param Closure(): mixed $migrate */
    public function run(Connection $connection, string $name, bool $transactional, ?OutputInterface $output, Closure $migrate): void
    {
        $settings = $this->guard->settings();
        $server = Server::of($connection);

        if ($server === null || $server->engine === Engine::Sqlite || $settings->lockTimeoutSeconds === 0 || ! $this->guard->active($settings)) {
            $migrate();

            return;
        }

        $this->hook($connection, $server);
        $previous = $this->set($connection, $server, (string) $settings->lockTimeoutSeconds);

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $this->watch($connection, $migrate);

                    return;
                } catch (QueryException $exception) {
                    if (! $this->timedOut($exception, $server)) {
                        throw $exception;
                    }

                    $retryable = $transactional || ! $this->applied;

                    if (! $retryable || $attempt > $settings->lockTimeoutRetries) {
                        throw LockTimeoutExceeded::after($name, $settings->lockTimeoutSeconds, $attempt, $retryable, $exception);
                    }

                    $output?->write([
                        PHP_EOL,
                        sprintf('  <fg=yellow;options=bold>!</> Another transaction holds a lock the migration needs: attempt %d of %d in %d s.', $attempt + 1, $settings->lockTimeoutRetries + 1, $settings->lockTimeoutRetrySeconds),
                        PHP_EOL,
                        "  {$name} ",
                    ]);

                    sleep($settings->lockTimeoutRetrySeconds);
                    $this->set($connection, $server, (string) $settings->lockTimeoutSeconds);
                }
            }
        } finally {
            $this->restore($connection, $server, $previous);
        }
    }

    /**
     * Runs the migration, noting whether any of its statements was applied.
     *
     * @param  Closure(): mixed  $migrate
     */
    private function watch(Connection $connection, Closure $migrate): void
    {
        $this->watched = $connection;
        $this->applied = false;

        try {
            $migrate();
        } finally {
            $this->watched = null;
        }
    }

    /**
     * Lifts the timeout before a concurrent build or drop on PostgreSQL: one that times out
     * leaves an invalid index behind.
     */
    private function hook(Connection $connection, Server $server): void
    {
        if ($server->engine !== Engine::Postgres || isset($this->hooked[$connection])) {
            return;
        }

        $this->hooked[$connection] = true;

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if ($connection === $this->watched && Tokens::of($query, Dialect::Postgres)->contains('concurrently')) {
                $connection->getPdo()->exec('set lock_timeout = 0');
            }
        });
    }

    private function set(Connection $connection, Server $server, string $seconds): string
    {
        if ($server->engine === Engine::Postgres) {
            $previous = (string) $connection->scalar("select current_setting('lock_timeout')", [], false);
            $connection->statement("set lock_timeout = '{$seconds}s'");
        } else {
            $previous = (string) $connection->scalar('select @@session.lock_wait_timeout', [], false);
            $connection->statement("set session lock_wait_timeout = {$seconds}");
        }

        return $previous;
    }

    private function restore(Connection $connection, Server $server, string $previous): void
    {
        try {
            $connection->statement($server->engine === Engine::Postgres
                ? "set lock_timeout = '".str_replace("'", "''", $previous)."'"
                : 'set session lock_wait_timeout = '.(int) $previous);
        } catch (QueryException) {
            // The connection that failed the migration may be gone; the session goes with it.
        }
    }

    private function timedOut(QueryException $exception, Server $server): bool
    {
        return $server->engine === Engine::Postgres
            ? $exception->getCode() === '55P03'
            : ($exception->errorInfo[1] ?? null) === 1205;
    }
}
