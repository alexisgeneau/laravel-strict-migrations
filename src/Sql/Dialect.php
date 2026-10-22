<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

enum Dialect
{
    case MySql;
    case Postgres;
    case Sqlite;

    public static function forDriver(string $driver): self
    {
        return match ($driver) {
            'pgsql' => self::Postgres,
            'sqlite' => self::Sqlite,
            default => self::MySql,
        };
    }

    /** Double quotes delimit strings in MySQL unless ANSI_QUOTES is on, identifiers elsewhere. */
    public function doubleQuotesStrings(): bool
    {
        return $this === self::MySql;
    }

    public function backslashEscapes(): bool
    {
        return $this === self::MySql;
    }
}
