<?php

declare(strict_types=1);

use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Intent;

it('tells what a statement would do', function (string $sql, Dialect $dialect, Intent $intent) {
    expect(Intent::of($sql, $dialect))->toBe($intent);
})->with([
    ['select * from orders where id = ?', Dialect::Postgres, Intent::Read],
    ['select exists(select 1 from information_schema.tables where table_name = ?)', Dialect::Postgres, Intent::Read],
    ['with recent as (select * from orders) select count(*) from recent', Dialect::Postgres, Intent::Read],
    ['show columns from orders', Dialect::MySql, Intent::Read],
    ['explain select * from orders', Dialect::MySql, Intent::Read],
    ['pragma table_xinfo("orders")', Dialect::Sqlite, Intent::Read],
    ['pragma foreign_keys', Dialect::Sqlite, Intent::Read],
    ['insert into orders (email) values (?)', Dialect::Postgres, Intent::Write],
    ['alter table orders add column notes text', Dialect::Postgres, Intent::Write],
    ['select * into orders_copy from orders', Dialect::Postgres, Intent::Write],
    ["select nextval('orders_id_seq')", Dialect::Postgres, Intent::Write],
    ['select pg_advisory_lock(42)', Dialect::Postgres, Intent::Write],
    ["select get_lock('migrations', 10)", Dialect::MySql, Intent::Write],
    ['select @total := 0', Dialect::MySql, Intent::Write],
    ['with stale as (select id from orders) delete from orders where id in (select id from stale)', Dialect::Postgres, Intent::Write],
    ['explain analyze delete from orders', Dialect::Postgres, Intent::Write],
    ['pragma foreign_keys = off', Dialect::Sqlite, Intent::Write],
    ['set lock_timeout = 1000', Dialect::Postgres, Intent::Write],
    ['begin', Dialect::Postgres, Intent::Transaction],
    ['savepoint trans2', Dialect::Postgres, Intent::Transaction],
    ['release savepoint trans2', Dialect::Postgres, Intent::Transaction],
    ['set transaction isolation level serializable', Dialect::MySql, Intent::Transaction],
    ['select 1; delete from orders', Dialect::Postgres, Intent::Write],
    ['select 1; select 2', Dialect::Postgres, Intent::Read],
]);
