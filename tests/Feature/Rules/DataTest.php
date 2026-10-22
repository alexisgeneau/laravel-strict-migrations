<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;
use StrictMigrations\Tests\Database;

it('refuses rows changed in one statement on a large table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::table('orders')->where('status', 'new')->update(['status' => 'pending']));

    expect(verdicts($analysis))->toBe(['unsafe data-change'])
        ->and($analysis->findings[0]->message)->toBe(engine() === Engine::Postgres
            ? "Updating orders in the migration's transaction keeps every row it changes locked until the migration commits."
            : 'Updating orders in a migration changes every matching row in one statement: they stay locked until it ends, and on a large table it runs long.')
        ->and($analysis->findings[0]->statement)->toBe(engine()->isMySqlFamily()
            ? "update `orders` set `status` = 'pending' where `status` = 'new'"
            : "update \"orders\" set \"status\" = 'pending' where \"status\" = 'new'");
});

it('only warns about rows changed on a small table', function () {
    $this->table('orders', rows: 3);

    $analysis = $this->analyze(fn () => DB::table('orders')->where('status', 'new')->delete());

    expect(verdicts($analysis))->toBe(['warning data-change']);
});

it('refuses every row deleted from a table that holds some, whatever its size', function () {
    $this->table('orders', rows: 1);

    $analysis = $this->analyze(fn () => DB::table('orders')->delete());

    expect(verdicts($analysis))->toBe(['unsafe data-change'])
        ->and($analysis->findings[0]->message)->toBe('Deleting from orders without a WHERE clause deletes every row it holds.');
});

it('accepts rows inserted, and rows changed in a table the run created', function () {
    $this->table('statuses', rows: 6);

    $analysis = $this->analyze(function () {
        DB::table('statuses')->insert(['email' => 'new@example.com']);

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        DB::table('priorities')->insert([['name' => 'low'], ['name' => 'high']]);
        DB::table('priorities')->where('name', 'low')->update(['name' => 'normal']);
    });

    expect(verdicts($analysis))->toBe([]);
});

it('refuses rows changed after the schema in the same transaction', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        DB::table('orders')->update(['currency' => 'EUR']);
    });

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe schema-and-data'],
        default => ['unsafe data-change'],
    });
});

it('suggests batches in a migration of their own', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::table('orders')->update(['status' => 'archived']));

    expect($analysis->findings[0]->recipe)->toContain('public $withinTransaction = false;')
        ->toContain("DB::table('orders')->chunkById(1000, function (\$rows) {");
});

it('measures and names the tables of a connection with a prefix', function () {
    config(['database.connections.prefixed' => [...Database::connection(), 'prefix' => 'app_']]);
    Schema::connection('prefixed')->create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('status');
    });
    DB::connection('prefixed')->table('orders')->insert([['status' => 'new'], ['status' => 'new']]);

    $analysis = $this->analyze(fn () => DB::connection('prefixed')->table('orders')->update(['status' => 'archived']), connection: 'prefixed');

    expect(verdicts($analysis))->toBe(['warning data-change'])
        ->and($analysis->findings[0]->size?->describe())->toBe('app_orders: 2 rows, under the 5-row threshold')
        ->and($analysis->findings[0]->recipe)->toContain("DB::table('orders')->chunkById(1000");

    Schema::connection('prefixed')->drop('orders');
});
