<?php

declare(strict_types=1);

namespace StrictMigrations\Findings;

final readonly class Finding
{
    /**
     * @param  string  $statement  The first statement it was found in.
     * @param  int  $repeats  How many more statements of the migration it was found in.
     */
    public function __construct(
        public Severity $severity,
        public Kind $kind,
        public string $rule,
        public string $message,
        public string $statement,
        public ?string $recipe = null,
        public ?TableSize $size = null,
        public int $repeats = 0,
    ) {}

    public function refuses(): bool
    {
        return $this->severity === Severity::Unsafe;
    }

    /** Whether another finding says the same thing, about another statement. */
    public function sameAs(self $other): bool
    {
        return $this->severity === $other->severity && $this->rule === $other->rule && $this->message === $other->message;
    }

    /** The same finding, found in one more statement. */
    public function repeated(): self
    {
        return new self($this->severity, $this->kind, $this->rule, $this->message, $this->statement, $this->recipe, $this->size, $this->repeats + 1);
    }

    /** @return array{severity: string, kind: string, rule: string, message: string, statement: string, repeats: int, recipe: string|null, table: string|null, rows: int|null} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'kind' => $this->kind->value,
            'rule' => $this->rule,
            'message' => $this->message,
            'statement' => $this->statement,
            'repeats' => $this->repeats,
            'recipe' => $this->recipe,
            'table' => $this->size?->table,
            'rows' => $this->size?->rows,
        ];
    }
}
