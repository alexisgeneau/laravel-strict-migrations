<?php

declare(strict_types=1);

namespace StrictMigrations\Analysis;

use StrictMigrations\Database\Server;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Severity;

/** What the analysis of one migration found. */
final readonly class Analysis
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $statements  What the migration would send, in order.
     * @param  string|null  $assured  The reason given by #[SafetyAssured], when the migration has one.
     * @param  string|null  $skipped  Why the migration was not analyzed, when it was not.
     */
    public function __construct(
        public string $migration,
        public string $file,
        public ?string $connection,
        public ?Server $server = null,
        public array $findings = [],
        public array $statements = [],
        public ?string $assured = null,
        public ?string $skipped = null,
    ) {}

    public function refuses(): bool
    {
        return $this->with(Severity::Unsafe) !== [];
    }

    /** @return list<Finding> */
    public function with(Severity $severity): array
    {
        return array_values(array_filter($this->findings, fn (Finding $finding): bool => $finding->severity === $severity));
    }

    /** The most serious severity among the findings, or null when there are none. */
    public function worst(): ?Severity
    {
        foreach ([Severity::Unsafe, Severity::Warning, Severity::Assured, Severity::Note] as $severity) {
            if ($this->with($severity) !== []) {
                return $severity;
            }
        }

        return null;
    }

    /** @return array{migration: string, file: string, connection: string|null, server: string|null, assured: string|null, skipped: string|null, findings: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'migration' => $this->migration,
            'file' => $this->file,
            'connection' => $this->connection,
            'server' => $this->server?->describe(),
            'assured' => $this->assured,
            'skipped' => $this->skipped,
            'findings' => array_map(fn (Finding $finding): array => $finding->toArray(), $this->findings),
        ];
    }
}
