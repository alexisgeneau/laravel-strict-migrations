<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

use StrictMigrations\Sql\Dialect;

enum Engine
{
    case Postgres;
    case MySql;
    case MariaDb;
    case Sqlite;

    public function dialect(): Dialect
    {
        return match ($this) {
            self::Postgres => Dialect::Postgres,
            self::MySql, self::MariaDb => Dialect::MySql,
            self::Sqlite => Dialect::Sqlite,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Postgres => 'PostgreSQL',
            self::MySql => 'MySQL',
            self::MariaDb => 'MariaDB',
            self::Sqlite => 'SQLite',
        };
    }

    public function isMySqlFamily(): bool
    {
        return $this === self::MySql || $this === self::MariaDb;
    }
}
