<?php

declare(strict_types=1);

namespace StrictMigrations\Analysis;

/**
 * What one run of migrations has done so far: the tables it created are new to production,
 * so nothing that happens to them can lock a live table or break running code.
 */
final class Run
{
    /** @var array<string, true> */
    private array $created = [];

    public function create(string $table): void
    {
        $this->created[self::key($table)] = true;
    }

    public function forget(string $table): void
    {
        unset($this->created[self::key($table)]);
    }

    public function rename(string $from, string $to): void
    {
        if ($this->created($from)) {
            $this->forget($from);
            $this->create($to);
        }
    }

    public function created(string $table): bool
    {
        return isset($this->created[self::key($table)]);
    }

    private static function key(string $table): string
    {
        return substr((string) strrchr('.'.$table, '.'), 1);
    }
}
