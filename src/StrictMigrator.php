<?php

declare(strict_types=1);

namespace StrictMigrations;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Analysis\Run;
use StrictMigrations\Analysis\Subject;

/**
 * Laravel's migrator, with every migration analyzed before its line is printed and run, and
 * run with a bounded wait for its locks.
 */
final class StrictMigrator extends Migrator
{
    private Run $run;

    public function __construct(
        MigrationRepositoryInterface $repository,
        ConnectionResolverInterface $resolver,
        Filesystem $files,
        ?Dispatcher $dispatcher,
        private readonly Guard $guard,
        private readonly LockTimeout $lockTimeout,
    ) {
        parent::__construct($repository, $resolver, $files, $dispatcher);

        $this->run = new Run;
    }

    public function runPending(array $migrations, array $options = [])
    {
        $this->run = new Run;

        parent::runPending($migrations, $options);
    }

    /** Analyzes a migration without running it, measuring its tables or assuming them large. */
    public function analyze(string $file, Run $run, bool $measures): ?Analysis
    {
        $subject = $this->subject($file, 'up');

        return $subject === null ? null : $this->guard->analyze($subject, $run, $measures);
    }

    protected function runUp($file, $batch, $pretend)
    {
        if (! $pretend) {
            $this->inspect($file, 'up');
        }

        parent::runUp($file, $batch, $pretend);
    }

    protected function runDown($file, $migration, $pretend)
    {
        if (! $pretend) {
            $this->inspect($file, 'down');
        }

        parent::runDown($file, $migration, $pretend);
    }

    protected function runMigration($migration, $method, $name = null)
    {
        if (! $migration instanceof Migration) {
            parent::runMigration($migration, $method, $name);

            return;
        }

        $connection = $this->resolveConnection($migration->getConnection());
        $name ??= $this->nameOf($migration);

        $this->lockTimeout->run(
            $connection,
            $name,
            $this->getSchemaGrammar($connection)->supportsSchemaTransactions() && $migration->withinTransaction,
            $this->output,
            fn () => parent::runMigration($migration, $method, $name),
        );
    }

    private function inspect(string $file, string $method): void
    {
        $subject = $this->subject($file, $method);

        if ($subject !== null) {
            $this->guard->inspect($subject, $this->run, $this->output);
        }
    }

    /** The migration in a file, ready to run one of its methods the way the migrator would; null when it will not run. */
    private function subject(string $file, string $method): ?Subject
    {
        $migration = $this->resolvePath($file);

        if (! $migration instanceof Migration || ! method_exists($migration, $method) || ($method === 'up' && ! $migration->shouldRun())) {
            return null;
        }

        $connection = $this->resolveConnection($migration->getConnection());

        return new Subject(
            $this->getMigrationName($file),
            $file,
            $migration,
            $method,
            $connection,
            $this->getSchemaGrammar($connection)->supportsSchemaTransactions() && $migration->withinTransaction,
            fn () => $this->runMethod($connection, $migration, $method),
        );
    }

    private function nameOf(object $migration): string
    {
        return $this->getMigrationName((string) (new ReflectionClass($migration))->getFileName());
    }
}
