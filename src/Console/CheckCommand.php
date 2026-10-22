<?php

declare(strict_types=1);

namespace StrictMigrations\Console;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Console\Migrations\BaseCommand;
use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Analysis\Run;
use StrictMigrations\Findings\Severity;
use StrictMigrations\Reports\ConsoleReport;
use StrictMigrations\Reports\GithubReport;
use StrictMigrations\Reports\JsonReport;
use StrictMigrations\Settings;
use StrictMigrations\StrictMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

#[AsCommand(name: 'migrate:check')]
final class CheckCommand extends BaseCommand
{
    protected $signature = 'migrate:check
                            {--database= : The database connection to check the migrations against}
                            {--path=* : The path(s) to the migrations files to check}
                            {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                            {--all : Check every migration, ran or not, whatever check_after says}
                            {--sizes : Measure the tables of this database instead of assuming every existing table is large}
                            {--format=console : console, github or json}';

    protected $description = 'Check the pending migrations for statements that would lock a table or break a deployment, without running them';

    protected StrictMigrator $migrator;

    public function handle(StrictMigrator $migrator, Repository $config): int
    {
        $this->migrator = $migrator;
        $format = $this->option('format');

        if (! in_array($format, ['console', 'github', 'json'], true)) {
            $this->components->error('The format must be console, github or json.');

            return self::INVALID;
        }

        $settings = Settings::fromConfig((array) $config->get('strict-migrations', []));

        try {
            $database = $this->option('database');
            $analyses = $migrator->usingConnection(is_string($database) ? $database : null, fn (): array => $this->analyze($settings));
        } catch (Throwable $exception) {
            $this->components->error('The migrations could not be checked: '.$exception->getMessage());

            return self::INVALID;
        }

        if ($format === 'json') {
            (new JsonReport($this->output))->render($analyses);
        } elseif ($format === 'github') {
            (new GithubReport($this->output, $this->laravel->basePath()))->render($analyses);
        } else {
            $this->report($analyses);
        }

        return array_filter($analyses, fn (Analysis $analysis): bool => $analysis->refuses()) === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Analysis> */
    private function analyze(Settings $settings): array
    {
        $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];
        $run = new Run;
        $analyses = [];

        foreach ($this->migrator->getMigrationFiles($this->getMigrationPaths()) as $name => $file) {
            if (! $this->option('all') && (in_array($name, $ran, true) || $settings->predates($name))) {
                continue;
            }

            if (($analysis = $this->migrator->analyze($file, $run, (bool) $this->option('sizes'))) !== null) {
                $analyses[] = $analysis;
            }
        }

        return $analyses;
    }

    /** @param list<Analysis> $analyses */
    private function report(array $analyses): void
    {
        if ($analyses === []) {
            $this->components->info('No migration to check.');

            return;
        }

        $report = new ConsoleReport($this->output);
        $this->newLine();

        foreach ($analyses as $analysis) {
            $report->findings($analysis, true);

            $this->components->twoColumnDetail(
                $analysis->assured === null ? $analysis->migration : $analysis->migration.' <fg=gray>'.OutputFormatter::escape($analysis->assured).'</>',
                match ($analysis->worst()) {
                    Severity::Unsafe => '<fg=red;options=bold>UNSAFE</>',
                    Severity::Warning => '<fg=yellow;options=bold>WARN</>',
                    default => match (true) {
                        $analysis->assured !== null => '<fg=blue;options=bold>ASSURED</>',
                        $analysis->skipped !== null => '<fg=gray>SKIPPED: '.OutputFormatter::escape($analysis->skipped).'</>',
                        default => '<fg=green;options=bold>OK</>',
                    },
                },
            );
        }

        $unsafe = count(array_filter($analyses, fn (Analysis $analysis): bool => $analysis->refuses()));
        $summary = sprintf('%d %s checked, %d unsafe.', count($analyses), count($analyses) === 1 ? 'migration' : 'migrations', $unsafe);

        $this->newLine();

        if ($unsafe === 0) {
            $this->components->info($summary);
        } else {
            $this->components->error($summary);
        }

        if ($this->assumedSizes($analyses)) {
            $this->components->warn('Tables were assumed large. --sizes measures them in this database, which only means something against production or a copy of it.');
        }
    }

    /** @param list<Analysis> $analyses */
    private function assumedSizes(array $analyses): bool
    {
        foreach ($analyses as $analysis) {
            foreach ($analysis->findings as $finding) {
                if ($finding->size !== null && $finding->size->rows === null && $finding->severity === Severity::Unsafe) {
                    return true;
                }
            }
        }

        return false;
    }
}
