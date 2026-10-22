<?php

declare(strict_types=1);

use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * The findings of an analysis as "severity rule", such as "unsafe index".
 *
 * @return list<string>
 */
function verdicts(Analysis $analysis): array
{
    return array_map(fn (Finding $finding): string => "{$finding->severity->value} {$finding->rule}", $analysis->findings);
}

/** The engine the tests run on, as STRICT_MIGRATIONS_TEST_DB names it. */
function engine(): Engine
{
    return match (getenv('STRICT_MIGRATIONS_TEST_DB') ?: 'sqlite') {
        'pgsql' => Engine::Postgres,
        'mysql' => Engine::MySql,
        'mariadb' => Engine::MariaDb,
        default => Engine::Sqlite,
    };
}
