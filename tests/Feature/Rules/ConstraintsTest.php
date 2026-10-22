<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;

beforeEach(function () {
    $this->table('customers', rows: 6);
    $this->table('orders', rows: 6);
});

it('refuses a foreign key that checks every row while blocking writes', function () {
    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->foreign('customer_id')->references('id')->on('customers')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres, Engine::MySql, Engine::MariaDb => ['unsafe foreign-key'],
        Engine::Sqlite => ['note table-rebuild'],
    });
});

it('accepts a foreign key added without checking the existing rows', function () {
    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->foreign('customer_id')->references('id')->on('customers')->notValid()));

    expect(verdicts($analysis))->toBe(['note foreign-key'])
        ->and($analysis->findings[0]->recipe)->toBe('    DB::statement(\'alter table "orders" validate constraint "orders_customer_id_foreign"\');');
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('accepts a foreign key added in place with the checks off', function () {
    $analysis = $this->analyze(fn () => Schema::withoutForeignKeyConstraints(function () {
        Schema::table('orders', fn (Blueprint $table) => $table->foreign('customer_id')->references('id')->on('customers'));
    }));

    expect(verdicts($analysis))->toBe([]);
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('refuses a foreign key added in place with the checks on, which MySQL rejects', function () {
    $analysis = $this->analyze(fn () => DB::statement('alter table orders add constraint orders_customer_id_foreign foreign key (customer_id) references customers (id), algorithm=inplace, lock=none'));

    expect(verdicts($analysis))->toBe(['unsafe foreign-key'])
        ->and($analysis->findings[0]->kind->value)->toBe('error');
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('gives the recipe of a foreign key in the form of the blueprint', function () {
    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->foreign('customer_id')->references('id')->on('customers')));

    expect($analysis->findings[0]->recipe)->toContain("\$table->foreign('customer_id')->references('id')->on('customers');");
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('refuses a check constraint that scans the table while it is locked', function () {
    $analysis = $this->analyze(fn () => DB::statement('alter table orders add constraint orders_total_positive check (total >= 0)'));

    expect(verdicts($analysis))->toBe(['unsafe check-constraint']);
})->skip(engine() === Engine::Sqlite, 'SQLite cannot add a constraint to a table.');

it('accepts a check constraint added without checking the existing rows', function () {
    $analysis = $this->analyze(fn () => DB::statement('alter table orders add constraint orders_total_positive check (total >= 0) not valid'));

    expect(verdicts($analysis))->toBe([])
        ->and($analysis->statements)->toBe(['alter table orders add constraint orders_total_positive check (total >= 0) not valid']);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('accepts a unique constraint attached to an existing index', function () {
    DB::statement('create unique index orders_email_unique on orders (email)');

    $analysis = $this->analyze(fn () => DB::statement('alter table orders add constraint orders_email_unique unique using index orders_email_unique'));

    expect(verdicts($analysis))->toBe([]);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('accepts constraints dropped or renamed', function () {
    DB::statement('alter table orders add constraint orders_total_positive check (total >= 0)');

    $analysis = $this->analyze(fn () => DB::statement(engine() === Engine::Postgres
        ? 'alter table orders rename constraint orders_total_positive to orders_total_not_negative'
        : 'alter table orders drop constraint orders_total_positive'));

    expect(verdicts($analysis))->toBe([]);
})->skip(engine() === Engine::Sqlite, 'SQLite cannot alter a constraint.');
