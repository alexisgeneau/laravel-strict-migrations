<?php

declare(strict_types=1);

namespace StrictMigrations\Reports;

use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/** Workflow commands that GitHub Actions shows as annotations on the migration files of a pull request. */
final class GithubReport
{
    public function __construct(
        private readonly OutputInterface $output,
        private readonly string $basePath,
    ) {}

    /** @param list<Analysis> $analyses */
    public function render(array $analyses): void
    {
        foreach ($analyses as $analysis) {
            foreach ($analysis->findings as $finding) {
                if ($finding->severity === Severity::Unsafe || $finding->severity === Severity::Warning) {
                    $this->annotate($analysis, $finding);
                }
            }
        }
    }

    private function annotate(Analysis $analysis, Finding $finding): void
    {
        $file = str_starts_with($analysis->file, $this->basePath.'/') ? substr($analysis->file, strlen($this->basePath) + 1) : $analysis->file;
        $statement = $finding->repeats === 0 ? $finding->statement : "{$finding->statement}\n(and {$finding->repeats} more like it)";
        $message = implode("\n\n", array_filter([$finding->message, $finding->size?->describe(), $statement, $finding->recipe]));

        $this->output->writeln(sprintf(
            '::%s file=%s,title=%s::%s',
            $finding->severity === Severity::Unsafe ? 'error' : 'warning',
            self::property($file),
            self::property(($finding->severity === Severity::Unsafe ? 'Unsafe migration' : 'Migration warning')." ({$finding->rule})"),
            self::data($message),
        ), OutputInterface::OUTPUT_RAW);
    }

    private static function data(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function property(string $value): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], self::data($value));
    }
}
