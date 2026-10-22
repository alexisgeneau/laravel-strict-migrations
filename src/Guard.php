<?php

declare(strict_types=1);

namespace StrictMigrations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Analysis\Analyzer;
use StrictMigrations\Analysis\Run;
use StrictMigrations\Analysis\Subject;
use StrictMigrations\Exceptions\UnsafeMigration;
use StrictMigrations\Reports\ConsoleReport;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/** Decides which migrations to analyze before they run, reports what it finds, and refuses the unsafe ones. */
final class Guard
{
    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function settings(): Settings
    {
        return Settings::fromConfig((array) $this->config->get('strict-migrations', []));
    }

    /** Whether the guard and the lock timeout are on in this environment. */
    public function active(Settings $settings): bool
    {
        return ! $settings->off() && ! $this->app->environment($settings->disabledIn);
    }

    /**
     * Analyzes a migration about to run, if it is watched, and reports what it finds.
     *
     * @throws UnsafeMigration
     */
    public function inspect(Subject $subject, Run $run, ?OutputInterface $output): void
    {
        $settings = $this->settings();

        if (! $this->watches($settings, $subject)) {
            return;
        }

        $analysis = $this->analyzer->analyze($subject, $settings, $run, true);

        if ($output !== null && $analysis->assured !== null) {
            (new ConsoleReport($output))->assured($analysis);
        } elseif ($output !== null) {
            (new ConsoleReport($output))->findings($analysis, $settings->blocks());
        }

        if ($analysis->refuses() && $settings->blocks()) {
            $output?->writeln([
                sprintf('  %s <fg=gray>%s</> <fg=red;options=bold>REFUSED</>', $subject->name, str_repeat('.', max(min((new Terminal)->getWidth(), 150) - mb_strlen($subject->name) - 15, 1))),
                '',
            ]);

            throw new UnsafeMigration($analysis, $output !== null);
        }
    }

    public function analyze(Subject $subject, Run $run, bool $measures): Analysis
    {
        return $this->analyzer->analyze($subject, $this->settings(), $run, $measures);
    }

    private function watches(Settings $settings, Subject $subject): bool
    {
        return $this->active($settings)
            && ($subject->method === 'up' || $settings->checkDown)
            && ! $settings->predates($subject->name)
            && $settings->watches($subject->connection->getName());
    }
}
