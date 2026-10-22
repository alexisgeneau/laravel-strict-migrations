<?php

declare(strict_types=1);

use StrictMigrations\Database\PostgresTypes;

it('reads the names PostgreSQL reports in their usual form', function (string $type, string $canonical) {
    expect(PostgresTypes::canonical($type))->toBe($canonical);
})->with([
    ['character varying(255)', 'varchar(255)'],
    ['timestamp(0) without time zone', 'timestamp(0)'],
    ['timestamp with time zone', 'timestamptz'],
    ['NUMERIC(10, 2)', 'numeric(10,2)'],
    ['int4', 'integer'],
    ['bigserial', 'bigint'],
    ['character varying[]', 'varchar[]'],
]);

it('knows which type changes rewrite the table', function (string $from, string $to, bool $utc, bool $rewrites) {
    expect(PostgresTypes::rewrites($from, $to, $utc))->toBe($rewrites);
})->with([
    'a longer varchar' => ['character varying(50)', 'varchar(100)', true, false],
    'a shorter varchar' => ['character varying(100)', 'varchar(50)', true, true],
    'varchar to text' => ['character varying(255)', 'text', true, false],
    'text to varchar' => ['text', 'varchar', true, false],
    'text to a bounded varchar' => ['text', 'varchar(255)', true, true],
    'varchar unbounded' => ['character varying(255)', 'varchar', true, false],
    'integer to bigint' => ['integer', 'bigint', true, true],
    'a larger numeric' => ['numeric(10,2)', 'numeric(12,2)', true, false],
    'a numeric with another scale' => ['numeric(10,2)', 'numeric(12,3)', true, true],
    'a numeric unbounded' => ['numeric(10,2)', 'numeric', true, false],
    'a bounded numeric' => ['numeric', 'numeric(10,2)', true, true],
    'timestamp to timestamptz in UTC' => ['timestamp(0) without time zone', 'timestamptz', true, false],
    'timestamp to timestamptz elsewhere' => ['timestamp(0) without time zone', 'timestamptz', false, true],
    'a less precise timestamp' => ['timestamp(6) without time zone', 'timestamp(0)', true, true],
    'a more precise timestamp' => ['timestamp(0) without time zone', 'timestamp(3)', true, false],
    'the same type' => ['int4', 'integer', true, false],
    'json to jsonb' => ['json', 'jsonb', true, true],
]);
