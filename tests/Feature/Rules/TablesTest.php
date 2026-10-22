<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;

it('refuses a rename of a table the running code still uses', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::rename('orders', 'purchases'));

    expect(verdicts($analysis))->toBe(['unsafe rename-table'])
        ->and($analysis->findings[0]->recipe)->toContain(engine()->isMySqlFamily()
            ? 'create view `orders` as select * from `purchases`'
            : 'create view "orders" as select * from "purchases"');
});

it('refuses a dropped table, whatever its size', function () {
    $this->table('orders');

    $analysis = $this->analyze(fn () => Schema::drop('orders'));

    expect(verdicts($analysis))->toBe(['unsafe drop-table']);
});

it('ignores a table dropped if it exists when it does not', function () {
    $analysis = $this->analyze(fn () => Schema::dropIfExists('legacy_orders'));

    expect(verdicts($analysis))->toBe([]);
});

it('ignores a table created and dropped by the run', function () {
    $analysis = $this->analyze(function () {
        Schema::create('imports', fn (Blueprint $table) => $table->id());
        Schema::rename('imports', 'pending_imports');
        Schema::drop('pending_imports');
    });

    expect(verdicts($analysis))->toBe([]);
});

it('refuses a truncated table that holds rows', function () {
    $this->table('orders', rows: 1);
    $this->table('sessions');

    $populated = $this->analyze(fn () => DB::table('orders')->truncate());
    $empty = $this->analyze(fn () => DB::table('sessions')->truncate());

    expect(verdicts($populated))->toBe(engine() === Engine::Sqlite ? ['unsafe data-change'] : ['unsafe truncate'])
        ->and(verdicts($empty))->toBe([]);
});

it('refuses a table locked explicitly', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement(engine() === Engine::Postgres ? 'lock table orders in access exclusive mode' : 'lock tables orders write'));

    expect(verdicts($analysis))->toBe(['unsafe lock-table']);
})->skip(engine() === Engine::Sqlite, 'SQLite has no table lock.');

it('refuses a statement that rewrites the whole table', function (string $statement) {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement($statement), withinTransaction: false);

    expect(verdicts($analysis))->toBe(['unsafe table-rebuild']);
})->with(fn () => match (engine()) {
    Engine::Postgres => ['vacuum full orders', 'cluster orders using orders_pkey', 'alter table orders set logged', 'alter table orders set tablespace pg_default'],
    Engine::MySql, Engine::MariaDb => ['alter table orders convert to character set latin1', 'alter table orders engine = MyISAM', 'alter table orders order by email'],
    Engine::Sqlite => ['vacuum'],
})->skip(engine() === Engine::Sqlite, 'SQLite rebuilds nothing in place.');

it('accepts a rebuild MySQL carries out in place', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('alter table orders engine = InnoDB'));

    expect(verdicts($analysis))->toBe([]);
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('judges the maintenance commands of PostgreSQL', function (string $statement, bool $withinTransaction, array $verdicts) {
    $this->table('orders', rows: 6);
    DB::statement('create materialized view order_totals as select customer_id, sum(total) as total from orders group by customer_id');

    $analysis = $this->analyze(fn () => DB::statement($statement), withinTransaction: $withinTransaction);

    expect(verdicts($analysis))->toBe($verdicts);
})->with([
    'vacuum' => ['vacuum analyze orders', false, []],
    'vacuum in a transaction' => ['vacuum orders', true, ['unsafe maintenance-in-transaction']],
    'reindex' => ['reindex table orders', true, ['unsafe reindex']],
    'reindex concurrently' => ['reindex table concurrently orders', false, []],
    'reindex concurrently in a transaction' => ['reindex table concurrently orders', true, ['unsafe maintenance-in-transaction']],
    'refresh' => ['refresh materialized view order_totals', true, ['unsafe refresh-materialized-view']],
    'refresh concurrently' => ['refresh materialized view concurrently order_totals', true, []],
])->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('refuses a statement it cannot read', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement(match (engine()) {
        Engine::Postgres => 'alter table orders attach partition orders_2026 for values from (1) to (100)',
        Engine::Sqlite => "attach database ':memory:' as scratch",
        default => 'alter table orders partition by hash (id) partitions 4',
    }));

    expect(verdicts($analysis))->toBe(['unsafe unread'])
        ->and($analysis->findings[0]->message)->toBe('This statement could not be analyzed: check what it locks, and for how long, before it runs on a large table.');
});

it('accepts the statements that lock no table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        DB::statement('create view recent_orders as select * from orders where id > 3');
        DB::statement('drop view recent_orders');

        if (engine() === Engine::Postgres) {
            DB::statement("comment on table orders is 'What the customers bought'");
            DB::statement('create extension if not exists pgcrypto');
            DB::statement('create sequence invoice_numbers');
            DB::statement("create type order_status as enum ('new', 'paid')");
            DB::statement('analyze orders');
        }
    });

    expect(verdicts($analysis))->toBe([]);
});
