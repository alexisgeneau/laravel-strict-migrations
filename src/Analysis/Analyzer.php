<?php

declare(strict_types=1);

namespace StrictMigrations\Analysis;

use Illuminate\Database\Connection;
use ReflectionClass;
use StrictMigrations\Attributes\SafetyAssured;
use StrictMigrations\Database\Inspector;
use StrictMigrations\Database\Server;
use StrictMigrations\DryRun\DryRun;
use StrictMigrations\DryRun\DryRunInterrupted;
use StrictMigrations\DryRun\RecordedStatement;
use StrictMigrations\DryRun\Recording;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Kind;
use StrictMigrations\Findings\Severity;
use StrictMigrations\Operations\CreateTable;
use StrictMigrations\Operations\DataChange;
use StrictMigrations\Operations\DropTable;
use StrictMigrations\Operations\Operation;
use StrictMigrations\Operations\RebuildTable;
use StrictMigrations\Operations\RenameTable;
use StrictMigrations\Settings;
use StrictMigrations\Sql\Classifier;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Tokenizer;
use Throwable;

/**
 * Runs a migration dry, reads what it would send, and judges every statement against the
 * database it would run on.
 */
final class Analyzer
{
    public function __construct(
        private readonly DryRun $dryRun,
        private readonly Classifier $classifier,
    ) {}

    public function analyze(Subject $subject, Settings $settings, Run $run, bool $measures): Analysis
    {
        $connection = $subject->connection;

        if (($reason = $this->assuredReason($subject->migration)) !== null) {
            return new Analysis($subject->name, $subject->file, $connection->getName(), assured: $reason);
        }

        $server = Server::of($connection);

        if ($server === null) {
            return new Analysis($subject->name, $subject->file, $connection->getName(), skipped: "the {$connection->getDriverName()} driver is not analyzed");
        }

        $dialect = $server->engine->dialect();
        $recording = $this->dryRun->record($connection, $dialect, $subject->run);
        $inspector = new Inspector($connection, $server, $measures, $settings->smallTableRows);
        $context = new Context($server, $inspector, $settings, $run, $subject->transactional, $connection->getTablePrefix());
        $findings = [];

        foreach ($this->operations($recording, $connection, $dialect) as [$statement, $assured, $operation]) {
            $context->assessing($statement, $assured);
            array_push($findings, ...$operation->assess($context));
            $operation->apply($context);
        }

        if (($interruption = $recording->interruption()) !== null) {
            $findings[] = $this->interruption($interruption);
        }

        return new Analysis(
            $subject->name,
            $subject->file,
            $connection->getName(),
            $server,
            $this->grouped(array_filter($findings, fn (Finding $finding): bool => ! in_array($finding->rule, $settings->disabledRules, true))),
            array_map(fn (RecordedStatement $statement): string => $statement->sql, $recording->statements()),
        );
    }

    /**
     * The findings, each reported once with the number of other statements it was found in:
     * a migration that updates rows in a loop gets one finding, not one per row.
     *
     * @param  array<Finding>  $findings
     * @return list<Finding>
     */
    private function grouped(array $findings): array
    {
        /** @var list<Finding> $grouped */
        $grouped = [];

        foreach ($findings as $finding) {
            foreach ($grouped as $index => $kept) {
                if ($kept->sameAs($finding)) {
                    $grouped[$index] = $kept->repeated();

                    continue 2;
                }
            }

            $grouped[] = $finding;
        }

        return $grouped;
    }

    /** @return list<array{string, bool, Operation}> Each operation with the statement it came from, as displayed, and whether it was assured. */
    private function operations(Recording $recording, Connection $connection, Dialect $dialect): array
    {
        $operations = [];

        foreach ($recording->statements() as $recorded) {
            foreach (Tokenizer::split($recorded->sql, $dialect) as $sql) {
                $display = $recorded->bindings === [] || ! array_is_list($recorded->bindings) ? $sql : $connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $recorded->bindings);

                foreach ($this->classifier->classify($sql, $dialect) as $operation) {
                    $operations[] = [$display, $recorded->assured, $operation];
                }
            }
        }

        return $this->foldRebuilds($operations);
    }

    /**
     * Laravel alters a SQLite table by copying it: a temporary table, the rows copied over, the
     * original dropped and the copy renamed. That reads as one rebuild, not as a dropped table.
     *
     * @param  list<array{string, bool, Operation}>  $operations
     * @return list<array{string, bool, Operation}>
     */
    private function foldRebuilds(array $operations): array
    {
        $folded = [];
        $pending = [];

        foreach ($operations as [$statement, $assured, $operation]) {
            if ($operation instanceof CreateTable && str_starts_with($name = $this->unqualified($operation->table), '__temp__')) {
                $table = substr($operation->table, 0, -strlen($name)).substr($name, 8);
                $pending[$operation->table] = $table;
                $folded[] = [$statement, $assured, new RebuildTable($table, 'rebuilding it')];

                continue;
            }

            $temporary = match (true) {
                $operation instanceof DataChange && isset($pending[$operation->table]) => $operation->table,
                $operation instanceof DropTable && in_array($operation->table, $pending, true) => array_search($operation->table, $pending, true),
                $operation instanceof RenameTable && isset($pending[$operation->from]) => $operation->from,
                default => null,
            };

            if ($temporary === null) {
                $folded[] = [$statement, $assured, $operation];
            } elseif ($operation instanceof RenameTable) {
                unset($pending[$temporary]);
            }
        }

        return $folded;
    }

    private function unqualified(string $table): string
    {
        return substr((string) strrchr('.'.$table, '.'), 1);
    }

    private function interruption(Throwable $exception): Finding
    {
        $message = $exception instanceof DryRunInterrupted
            ? $exception->getMessage()
            : sprintf(
                'The analysis stopped where the migration threw %s (%s): the rest of the migration was not analyzed.',
                $exception::class,
                rtrim((string) strtok($exception->getMessage(), "\n"), '.'),
            );

        return new Finding(Severity::Warning, Kind::Unread, 'interrupted', $message, '');
    }

    private function assuredReason(object $migration): ?string
    {
        $attributes = (new ReflectionClass($migration))->getAttributes(SafetyAssured::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->reason;
    }
}
