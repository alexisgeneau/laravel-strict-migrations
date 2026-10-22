<?php

declare(strict_types=1);

namespace StrictMigrations\Findings;

final readonly class TableSize
{
    /**
     * @param  int|null  $rows  Counted up to the limit plus one, null when not measured.
     */
    public function __construct(
        public string $table,
        public ?int $rows,
        public int $limit,
    ) {}

    public function small(): bool
    {
        return $this->rows !== null && $this->rows <= $this->limit;
    }

    public function describe(): string
    {
        return match (true) {
            $this->rows === null => "{$this->table}: size not measured, assumed large",
            $this->small() => sprintf('%s: %s %s, under the %s-row threshold', $this->table, number_format($this->rows), $this->rows === 1 ? 'row' : 'rows', number_format($this->limit)),
            default => sprintf('%s: more than %s rows', $this->table, number_format($this->limit)),
        };
    }
}
