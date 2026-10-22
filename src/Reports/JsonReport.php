<?php

declare(strict_types=1);

namespace StrictMigrations\Reports;

use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Findings\Severity;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonReport
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /** @param list<Analysis> $analyses */
    public function render(array $analyses): void
    {
        $count = fn (Severity $severity): int => array_sum(array_map(fn (Analysis $analysis): int => count($analysis->with($severity)), $analyses));

        $this->output->writeln((string) json_encode([
            'unsafe' => $count(Severity::Unsafe),
            'warnings' => $count(Severity::Warning),
            'migrations' => array_map(fn (Analysis $analysis): array => $analysis->toArray(), $analyses),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
    }
}
