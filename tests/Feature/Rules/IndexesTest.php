<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use StrictMigrations\Analysis\Run;
use StrictMigrations\Database\Engine;

it('refuses an index that blocks the writes to a large table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe index'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('only warns about an index on a small table', function () {
    $this->table('orders', rows: 5);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['warning index'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('assumes a table it does not measure to be large', function () {
    $this->table('orders');

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')), measures: false);

    expect(verdicts($analysis))->toBe(['unsafe index'])
        ->and($analysis->findings[0]->size?->describe())->toBe('orders: size not measured, assumed large');
})->skip(engine() !== Engine::Postgres, 'Only PostgreSQL blocks writes to build an index.');

it('lets anything happen to a table the run created', function () {
    $analysis = $this->analyze(function () {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index();
            $table->string('reference')->unique();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['customer_id', 'reference']);
            $table->string('status')->default('new');
        });
    });

    expect(verdicts($analysis))->toBe([]);
});

it('remembers the tables created by earlier migrations of the run', function () {
    $this->table('orders', rows: 6);
    $run = new Run;
    $run->create('orders');

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')), run: $run);

    expect(verdicts($analysis))->toBe([]);
});

it('accepts an index built concurrently, outside a transaction', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')->online()), withinTransaction: false);

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['note index'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('refuses a concurrent build inside the migration transaction, which fails', function () {
    $this->table('orders');

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')->online()));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe index-in-transaction'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('gives the concurrent form of the index in its recipe', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', function (Blueprint $table) {
        $table->index(['customer_id', 'status']);
        $table->index('email', 'orders_by_email');
    }));

    if (engine() !== Engine::Postgres) {
        expect($analysis->refuses())->toBeFalse();

        return;
    }

    expect($analysis->findings[0]->recipe)->toContain("\$table->index(['customer_id', 'status'])->online();")
        ->and($analysis->findings[1]->recipe)->toContain("\$table->index('email', 'orders_by_email')->online();");
});

it('writes the recipe of an expression index as SQL', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('create index orders_lower_email on orders (lower(email))'));

    expect(verdicts($analysis))->toBe(['unsafe index'])
        ->and($analysis->findings[0]->recipe)->toContain("DB::statement('create index concurrently orders_lower_email on orders (lower(email))');");
})->skip(engine() !== Engine::Postgres, 'Only PostgreSQL blocks writes to build an index.');

it('refuses a unique constraint built while the table is locked', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->unique('email')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe unique-constraint'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('accepts a unique constraint attached to an index built concurrently', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->unique('email')->online()), withinTransaction: false);

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['note index'],
        Engine::MySql, Engine::MariaDb => ['note index'],
        Engine::Sqlite => [],
    });
});

it('refuses a fulltext index, which MySQL builds while blocking writes', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->fullText('notes')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe index'],
        Engine::MySql, Engine::MariaDb => ['unsafe index'],
        Engine::Sqlite => [],
    });
})->skip(engine() === Engine::Sqlite, 'SQLite has no fulltext index.');

it('warns that dropping an index waits for the queries on its table', function () {
    $this->table('orders', rows: 6, columns: fn (Blueprint $table) => $table->index('customer_id'));

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['customer_id'])));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['warning drop-index'],
        default => [],
    });
});

it('accepts an index dropped concurrently outside a transaction', function () {
    $this->table('orders', rows: 6, columns: fn (Blueprint $table) => $table->index('customer_id'));

    $outside = $this->analyze(fn () => DB::statement('drop index concurrently if exists orders_customer_id_index'), withinTransaction: false);
    $inside = $this->analyze(fn () => DB::statement('drop index concurrently if exists orders_customer_id_index'));

    expect(verdicts($outside))->toBe([])
        ->and(verdicts($inside))->toBe(['unsafe index-in-transaction']);
})->skip(engine() !== Engine::Postgres, 'Only PostgreSQL drops an index concurrently.');

it('refuses a primary key built while the table is locked', function () {
    Schema::create('events', function (Blueprint $table) {
        $table->uuid('uuid');
        $table->string('name')->nullable();
    });
    DB::table('events')->insert(array_map(fn () => ['uuid' => (string) Str::uuid()], range(1, 6)));

    $analysis = $this->analyze(fn () => Schema::table('events', fn (Blueprint $table) => $table->primary('uuid')));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe primary-key'],
        Engine::MySql, Engine::MariaDb => ['note primary-key'],
        Engine::Sqlite => ['note table-rebuild'],
    });
});

it('refuses a primary key dropped on its own, which copies a MySQL table', function () {
    Schema::create('events', function (Blueprint $table) {
        $table->uuid('uuid')->primary();
        $table->uuid('legacy_uuid');
    });
    DB::table('events')->insert(array_map(fn () => ['uuid' => (string) Str::uuid(), 'legacy_uuid' => (string) Str::uuid()], range(1, 6)));

    $alone = $this->analyze(fn () => Schema::table('events', fn (Blueprint $table) => $table->dropPrimary()));
    $replaced = $this->analyze(fn () => DB::statement('alter table events drop primary key, add primary key (legacy_uuid)'));

    expect(verdicts($alone))->toBe(['unsafe primary-key'])
        ->and(verdicts($replaced))->toBe(['note primary-key']);
})->skip(! engine()->isMySqlFamily(), 'Only MySQL and MariaDB copy the table to drop a primary key.');
