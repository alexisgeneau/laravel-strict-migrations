<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;

it('accepts a nullable column, or one with a constant default', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', function (Blueprint $table) {
        $table->string('reference')->nullable();
        $table->boolean('archived')->default(false);
        $table->integer('position')->default(0)->after('status');
    }));

    expect(verdicts($analysis))->toBe([]);
});

it('refuses a column whose default rewrites the table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->uuid('public_id')->default(DB::raw(match (engine()) {
        Engine::Postgres => 'gen_random_uuid()',
        Engine::Sqlite => '(lower(hex(randomblob(16))))',
        default => '(uuid())',
    }))));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres, Engine::MySql, Engine::MariaDb => ['unsafe column-default'],
        Engine::Sqlite => ['unsafe sqlite-add-column'],
    });
});

it('accepts a default computed once for the whole table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->timestamp('imported_at')->nullable()->useCurrent()));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Sqlite => ['unsafe sqlite-add-column'],
        default => [],
    });
});

it('refuses a NOT NULL column without a default on a table that holds rows', function () {
    $this->table('orders', rows: 2);
    $migration = fn () => Schema::table('orders', fn (Blueprint $table) => $table->string('reference'));

    expect(verdicts($this->analyze($migration)))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe not-null-without-default'],
        Engine::Sqlite => ['unsafe sqlite-add-column'],
        default => [],
    });

    if (engine()->isMySqlFamily()) {
        $migration();

        expect(DB::table('orders')->pluck('reference')->all())->toBe(['', '']);
    } else {
        expect($migration)->toThrow(QueryException::class);
    }
});

it('accepts a NOT NULL column without a default on an empty table', function () {
    $this->table('orders');
    $migration = fn () => Schema::table('orders', fn (Blueprint $table) => $table->string('reference'));

    expect(verdicts($this->analyze($migration)))->toBe([]);

    $migration();

    expect(Schema::hasColumn('orders', 'reference'))->toBeTrue();
});

it('refuses a NOT NULL date without a default, which strict mode rejects on a MySQL table that holds rows', function () {
    $this->table('orders', rows: 2);
    $migration = fn () => Schema::table('orders', fn (Blueprint $table) => $table->date('shipped_on'));

    expect(verdicts($this->analyze($migration)))->toBe(['unsafe not-null-without-default'])
        ->and($migration)->toThrow(QueryException::class);
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('refuses a column SQLite cannot add to a table that holds rows', function (Closure $column) {
    $this->table('orders', rows: 2);
    $migration = fn () => Schema::table('orders', $column);

    expect(verdicts($this->analyze($migration)))->toBe(['unsafe sqlite-add-column'])
        ->and($migration)->toThrow(QueryException::class);
})->with([
    'NOT NULL without default' => fn (Blueprint $table) => $table->string('reference'),
    'CURRENT_TIMESTAMP default' => fn (Blueprint $table) => $table->timestamp('imported_at')->useCurrent(),
    'stored generated column' => fn (Blueprint $table) => $table->string('domain')->storedAs("substr(email, instr(email, '@') + 1)"),
])->skip(engine() !== Engine::Sqlite, 'SQLite only.');

it('refuses a UNIQUE column, which SQLite never adds', function () {
    $this->table('orders');

    $analysis = $this->analyze(fn () => DB::statement('alter table orders add column code varchar unique'));

    expect(verdicts($analysis))->toBe(['unsafe sqlite-add-column']);
})->skip(engine() !== Engine::Sqlite, 'SQLite only.');

it('refuses a generated column stored for every row', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->decimal('total_with_tax', 10, 2)->storedAs('total * 1.2')->nullable()));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Sqlite => ['unsafe sqlite-add-column'],
        default => ['unsafe column-rewrite'],
    });
});

it('accepts a virtual generated column', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->decimal('total_with_tax', 10, 2)->virtualAs('total * 1.2')->nullable()));

    expect(verdicts($analysis))->toBe([]);
})->skip(engine() === Engine::Postgres, 'PostgreSQL 16 has no virtual generated column.');

it('refuses an auto-increment column added to a large table', function () {
    Schema::create('legacy_events', fn (Blueprint $table) => $table->string('name'));
    DB::table('legacy_events')->insert(array_map(fn (int $row) => ['name' => "event {$row}"], range(1, 6)));

    $analysis = $this->analyze(fn () => Schema::table('legacy_events', fn (Blueprint $table) => $table->id()));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe column-rewrite'],
        Engine::MySql, Engine::MariaDb => ['unsafe auto-increment'],
        Engine::Sqlite => ['unsafe sqlite-add-column'],
    });
});

it('notes a json column that could be jsonb', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->json('metadata')->nullable()));

    expect(verdicts($analysis))->toBe(engine() === Engine::Postgres ? ['note json'] : []);
});

it('refuses a rename of a column the running code still uses', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->renameColumn('notes', 'comments')));

    expect(verdicts($analysis))->toBe(['unsafe rename-column']);
});

it('refuses a dropped column the running code still uses', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['notes', 'total'])));

    expect(verdicts($analysis))->toBe(['unsafe drop-column', 'unsafe drop-column'])
        ->and($analysis->findings[0]->message)->toBe('Dropping orders.notes breaks the code that still reads or writes it while the deployment runs.');
});

it('only notes a dropped column when the application is down during deployments', function () {
    config(['strict-migrations.deployment' => 'maintenance']);
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes')));

    expect(verdicts($analysis))->toBe(['note drop-column']);
});

it('accepts a type change that rewrites nothing', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', function (Blueprint $table) {
        $table->string('status', 30)->default('new')->change();
        $table->decimal('total', 12, 2)->nullable()->change();
    }));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => [],
        Engine::MySql, Engine::MariaDb => ['unsafe column-type'],
        Engine::Sqlite => ['note table-rebuild'],
    });
});

it('judges a change of sign by the engine', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->bigInteger('customer_id')->nullable()->change()));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => [],
        Engine::MySql, Engine::MariaDb => ['unsafe column-type'],
        Engine::Sqlite => ['note table-rebuild'],
    });
});

it('refuses an integer widened to a bigint on PostgreSQL', function () {
    $this->table('orders', rows: 6, columns: fn (Blueprint $table) => $table->integer('quantity')->nullable());

    $analysis = $this->analyze(fn () => Schema::table('orders', fn (Blueprint $table) => $table->bigInteger('quantity')->nullable()->change()));

    expect(verdicts($analysis))->toBe(['unsafe column-type'])
        ->and($analysis->findings[0]->message)->toBe('Changing orders.quantity from integer to bigint rewrites orders and its indexes while blocking reads and writes.');
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('refuses a cast written with USING', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('alter table orders alter column status type varchar(30) using upper(status)'));

    expect(verdicts($analysis))->toBe(['unsafe column-type']);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('converts a timestamp to timestamptz without a rewrite when the session runs in UTC', function () {
    $this->table('orders', rows: 6);
    $migration = fn () => DB::statement('alter table orders alter column created_at type timestamptz');

    DB::statement("set timezone = 'UTC'");
    $utc = verdicts($this->analyze($migration));

    DB::statement("set timezone = 'Europe/Paris'");
    $paris = verdicts($this->analyze($migration));

    expect($utc)->toBe([])->and($paris)->toBe(['unsafe column-type']);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('judges a MySQL column change the way MySQL carries it out', function (Closure $change, array $verdicts) {
    $this->table('orders', rows: 6, columns: function (Blueprint $table) {
        $table->string('code', 50)->nullable();
        $table->string('label')->nullable();
        $table->enum('priority', ['low', 'high'])->default('low');
        $table->index('email');
    });

    $analysis = $this->analyze(fn () => Schema::table('orders', $change));

    expect(verdicts($analysis))->toBe($verdicts);
})->with([
    'a new default' => [fn (Blueprint $table) => $table->string('status', 20)->default('pending')->change(), []],
    'a longer varchar, same length prefix' => [fn (Blueprint $table) => $table->string('code', 60)->nullable()->change(), []],
    'a longer varchar, longer length prefix' => [fn (Blueprint $table) => $table->string('code', 100)->nullable()->change(), ['unsafe column-type']],
    'a varchar beyond 255 bytes already' => [fn (Blueprint $table) => $table->string('label', 500)->nullable()->change(), []],
    'a shorter varchar' => [fn (Blueprint $table) => $table->string('code', 40)->nullable()->change(), ['unsafe column-type']],
    'an ENUM value appended' => [fn (Blueprint $table) => $table->enum('priority', ['low', 'high', 'urgent'])->default('low')->change(), []],
    'an ENUM value inserted' => [fn (Blueprint $table) => $table->enum('priority', ['low', 'medium', 'high'])->default('low')->change(), ['unsafe column-type']],
    'a column made NOT NULL' => [fn (Blueprint $table) => $table->string('code', 50)->change(), ['warning not-null']],
    'another collation' => [fn (Blueprint $table) => $table->string('code', 50)->nullable()->collation('utf8mb4_bin')->change(), []],
    'another collation, indexed' => [fn (Blueprint $table) => $table->string('email')->nullable()->collation('utf8mb4_bin')->change(), engine() === Engine::MariaDb ? [] : ['unsafe column-type']],
    'another character set' => [fn (Blueprint $table) => $table->string('code', 50)->nullable()->charset('latin1')->collation('latin1_swedish_ci')->change(), ['unsafe column-type']],
])->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('accepts a MySQL column change that refuses to block writes', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('alter table orders modify customer_id bigint null, algorithm=inplace, lock=none'));

    expect(verdicts($analysis))->toBe([]);
})->skip(! engine()->isMySqlFamily(), 'MySQL and MariaDB only.');

it('refuses a column made NOT NULL by a scan of a large table', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('alter table orders alter column email set not null'));

    expect(verdicts($analysis))->toBe(['unsafe not-null'])
        ->and($analysis->findings[0]->recipe)->toContain('check ("email" is not null) not valid');
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('accepts NOT NULL proven by a validated check constraint', function () {
    $this->table('orders', rows: 6);
    DB::statement('alter table orders add constraint orders_email_not_null check (email is not null)');

    $analysis = $this->analyze(fn () => DB::statement('alter table orders alter column email set not null'));

    expect(verdicts($analysis))->toBe(['note not-null']);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('ignores NOT NULL set on a column that already is', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::statement('alter table orders alter column status set not null'));

    expect(verdicts($analysis))->toBe([]);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');
