<?php

declare(strict_types=1);

use StrictMigrations\Database\ColumnState;
use StrictMigrations\Database\Engine;
use StrictMigrations\Database\MySqlTypes;
use StrictMigrations\Operations\ColumnDefinition;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Tokens;

function algorithm(ColumnState $current, string $change, Engine $engine = Engine::MySql): string
{
    return MySqlTypes::algorithm($current, ColumnDefinition::parse(Tokens::of($change, Dialect::MySql)), $engine);
}

it('decides how MySQL carries out a column change', function (ColumnState $current, string $change, string $algorithm) {
    expect(algorithm($current, $change))->toBe($algorithm);
})->with([
    'a new default' => [new ColumnState('varchar(20)', false, 'utf8mb4_unicode_ci'), "varchar(20) not null default 'pending'", MySqlTypes::INSTANT],
    'nullable kept by omission' => [new ColumnState('varchar(20)', true, 'utf8mb4_unicode_ci'), 'varchar(20)', MySqlTypes::INSTANT],
    'made NOT NULL' => [new ColumnState('varchar(20)', true, 'utf8mb4_unicode_ci'), 'varchar(20) not null', MySqlTypes::INPLACE],
    'made nullable by omission' => [new ColumnState('varchar(20)', false, 'utf8mb4_unicode_ci'), 'varchar(20)', MySqlTypes::INPLACE],
    'moved' => [new ColumnState('int', true), 'int null after `id`', MySqlTypes::INPLACE],
    'a longer varchar, same prefix' => [new ColumnState('varchar(50)', true, 'utf8mb4_unicode_ci'), 'varchar(63) null', MySqlTypes::INPLACE],
    'a longer varchar, longer prefix' => [new ColumnState('varchar(50)', true, 'utf8mb4_unicode_ci'), 'varchar(64) null', MySqlTypes::COPY],
    'a longer latin1 varchar' => [new ColumnState('varchar(50)', true, 'latin1_swedish_ci'), 'varchar(255) null', MySqlTypes::INPLACE],
    'a shorter varchar' => [new ColumnState('varchar(50)', true, 'utf8mb4_unicode_ci'), 'varchar(40) null', MySqlTypes::COPY],
    'an ENUM value appended' => [new ColumnState("enum('a','b')", false), "enum('a', 'b', 'c') not null", MySqlTypes::INSTANT],
    'an ENUM value inserted' => [new ColumnState("enum('a','c')", false), "enum('a', 'b', 'c') not null", MySqlTypes::COPY],
    'a SET grown past 8 members' => [new ColumnState("set('a','b','c','d','e','f','g','h')", true), "set('a','b','c','d','e','f','g','h','i') null", MySqlTypes::COPY],
    'int to bigint' => [new ColumnState('int', true), 'bigint null', MySqlTypes::COPY],
    'int(11) is int' => [new ColumnState('int(11)', true), 'integer null', MySqlTypes::INSTANT],
    'boolean is tinyint(1)' => [new ColumnState('tinyint(1)', false), 'boolean not null', MySqlTypes::INSTANT],
    'another character set' => [new ColumnState('varchar(50)', true, 'utf8mb4_unicode_ci'), 'varchar(50) character set latin1 null', MySqlTypes::COPY],
    'utf8 is utf8mb3' => [new ColumnState('varchar(50)', true, 'utf8mb3_general_ci'), 'varchar(50) character set utf8 null', MySqlTypes::INSTANT],
]);

it('decides a new collation by the engine and the indexes of the column', function (Engine $engine, bool $indexed, string $algorithm) {
    $current = new ColumnState('varchar(50)', true, 'utf8mb4_unicode_ci', $indexed);

    expect(algorithm($current, "varchar(50) collate 'utf8mb4_bin' null", $engine))->toBe($algorithm);
})->with([
    'MySQL' => [Engine::MySql, false, MySqlTypes::INPLACE],
    'MySQL, indexed' => [Engine::MySql, true, MySqlTypes::COPY],
    'MariaDB' => [Engine::MariaDb, false, MySqlTypes::INSTANT],
    'MariaDB, indexed' => [Engine::MariaDb, true, MySqlTypes::INPLACE],
]);
