<?php

declare(strict_types=1);

use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Tokenizer;
use StrictMigrations\Sql\TokenType;

it('splits a script into its statements', function (string $script, Dialect $dialect, array $statements) {
    expect(Tokenizer::split($script, $dialect))->toBe($statements);
})->with([
    'semicolons in strings and comments' => [
        "update orders set notes = 'a; b'; -- done; really\ndelete from carts /* ; */;",
        Dialect::Postgres,
        ["update orders set notes = 'a; b'", 'delete from carts /* ; */'],
    ],
    'dollar quotes' => [
        'create function touch() returns trigger as $body$ begin new.updated_at = now(); return new; end; $body$ language plpgsql; select 1',
        Dialect::Postgres,
        ['create function touch() returns trigger as $body$ begin new.updated_at = now(); return new; end; $body$ language plpgsql', 'select 1'],
    ],
    'nested comments' => [
        'select 1 /* outer /* inner; */ still a comment; */; select 2',
        Dialect::Postgres,
        ['select 1 /* outer /* inner; */ still a comment; */', 'select 2'],
    ],
    'backslash escapes' => [
        "insert into notes (body) values ('it\\'s; fine'); select 1",
        Dialect::MySql,
        ["insert into notes (body) values ('it\\'s; fine')", 'select 1'],
    ],
    'hash comments' => [
        "select 1; # a comment; with semicolons\nselect 2;",
        Dialect::MySql,
        ['select 1', 'select 2'],
    ],
    'a MySQL routine, kept whole' => [
        'create trigger orders_touch before update on orders for each row begin set new.updated_at = now(); end;',
        Dialect::MySql,
        ['create trigger orders_touch before update on orders for each row begin set new.updated_at = now(); end'],
    ],
    'empty statements' => [
        ';; select 1;;',
        Dialect::Sqlite,
        ['select 1'],
    ],
]);

it('reads identifiers the way each engine quotes them', function (string $sql, Dialect $dialect, TokenType $type, string $value) {
    $tokens = Tokenizer::tokenize($sql, $dialect);

    expect($tokens[0]->type)->toBe($type)
        ->and($tokens[0]->value)->toBe($value);
})->with([
    'MySQL backticks' => ['`Order Lines`', Dialect::MySql, TokenType::Identifier, 'Order Lines'],
    'MySQL double quotes, a string' => ['"pending"', Dialect::MySql, TokenType::String, 'pending'],
    'PostgreSQL double quotes' => ['"Order ""Lines"""', Dialect::Postgres, TokenType::Identifier, 'Order "Lines"'],
    'PostgreSQL escape string' => ["E'it\\'s'", Dialect::Postgres, TokenType::String, "it's"],
    'PostgreSQL standard string' => ["'C:\\temp'", Dialect::Postgres, TokenType::String, 'C:\\temp'],
    'SQLite brackets' => ['[order lines]', Dialect::Sqlite, TokenType::Identifier, 'order lines'],
    'a word, lowercased' => ['ALTER', Dialect::Sqlite, TokenType::Word, 'alter'],
    'a placeholder' => ['$1', Dialect::Postgres, TokenType::Parameter, '$1'],
    'an operator' => ['->>', Dialect::MySql, TokenType::Symbol, '->>'],
]);
