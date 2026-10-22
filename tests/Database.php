<?php

declare(strict_types=1);

namespace StrictMigrations\Tests;

/**
 * The database the tests run on, chosen by STRICT_MIGRATIONS_TEST_DB: sqlite (the default),
 * pgsql, mysql or mariadb, reached with the STRICT_MIGRATIONS_TEST_DB_* variables.
 */
final class Database
{
    /** @return array<string, mixed> */
    public static function connection(): array
    {
        $driver = getenv('STRICT_MIGRATIONS_TEST_DB') ?: 'sqlite';

        $server = [
            'host' => self::env('HOST', '127.0.0.1'),
            'database' => self::env('DATABASE', 'strict'),
            'username' => self::env('USERNAME', 'strict'),
            'password' => self::env('PASSWORD', 'secret'),
            'prefix' => '',
        ];

        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'port' => (int) self::env('PORT', '5432'),
                'charset' => 'utf8',
                'search_path' => 'public',
                ...$server,
            ],
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'port' => (int) self::env('PORT', '3306'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                ...$server,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    private static function env(string $key, string $default): string
    {
        return getenv("STRICT_MIGRATIONS_TEST_DB_{$key}") ?: $default;
    }
}
