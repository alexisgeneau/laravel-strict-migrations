<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;

final readonly class Server
{
    public function __construct(
        public Engine $engine,
        public string $version,
    ) {}

    /** The server behind a connection, or null when its driver is not one the rules cover. */
    public static function of(Connection $connection): ?self
    {
        $engine = match ($connection->getDriverName()) {
            'pgsql' => Engine::Postgres,
            'mariadb' => Engine::MariaDb,
            'mysql' => $connection instanceof MySqlConnection && $connection->isMaria() ? Engine::MariaDb : Engine::MySql,
            'sqlite' => Engine::Sqlite,
            default => null,
        };

        return $engine === null ? null : new self($engine, self::parse($connection->getServerVersion(), $engine));
    }

    public function atLeast(string $version): bool
    {
        return version_compare($this->version, $version, '>=');
    }

    public function describe(): string
    {
        return $this->engine->label().' '.$this->version;
    }

    /** "16.4 (Debian 16.4-1)", "8.0.46-0ubuntu0.24.04.4" or "5.5.5-10.6.12-MariaDB-log" read as 16.4, 8.0.46 and 10.6.12. */
    private static function parse(string $version, Engine $engine): string
    {
        if ($engine === Engine::MariaDb) {
            $version = preg_replace('/^5\.5\.5-/', '', $version) ?? $version;
        }

        return preg_match('/^\d+(?:\.\d+)*/', $version, $match) ? $match[0] : '0';
    }
}
