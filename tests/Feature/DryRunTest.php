<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;
use StrictMigrations\Tests\Database;
use StrictMigrations\Tests\Fixtures\MigrationCompleted;
use StrictMigrations\Tests\Fixtures\RecalculateTotals;

it('records what the migration would send without sending any of it', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 3)->nullable();
            $table->index('customer_id');
        });

        DB::table('orders')->update(['currency' => 'EUR']);
        DB::table('orders')->delete();
    });

    expect($analysis->statements)->toHaveCount(4)
        ->and(Schema::hasColumn('orders', 'currency'))->toBeFalse()
        ->and(Schema::hasIndex('orders', ['customer_id']))->toBeFalse()
        ->and(DB::table('orders')->count())->toBe(6);
});

it('lets the migration read the database as it stands', function () {
    $this->table('orders', rows: 6, columns: fn (Blueprint $table) => $table->string('currency', 3)->nullable());

    $analysis = $this->analyze(function () {
        if (! Schema::hasColumn('orders', 'currency')) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        }

        foreach (DB::table('orders')->where('id', '<=', 2)->orderBy('id')->get() as $order) {
            DB::table('orders')->where('id', $order->id)->update(['notes' => "order {$order->id}"]);
        }
    });

    expect($analysis->statements)->toHaveCount(2)
        ->and($analysis->statements[0])->toStartWith('update');
});

it('gives the migration an id for the rows it inserts', function () {
    $this->table('orders');

    $analysis = $this->analyze(function () {
        $id = DB::table('orders')->insertGetId(['email' => 'first@example.com']);
        DB::table('orders')->insert(['email' => "after {$id}@example.com"]);
    });

    expect($analysis->statements)->toHaveCount(2)
        ->and(DB::table('orders')->count())->toBe(0);
});

it('runs the transactions of the migration without opening any', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        DB::transaction(function () {
            DB::table('orders')->where('id', 1)->update(['status' => 'paid']);

            DB::transaction(fn () => DB::table('orders')->where('id', 2)->update(['status' => 'paid']));
        });
    });

    expect($analysis->statements)->toHaveCount(2)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::table('orders')->where('status', 'paid')->count())->toBe(0);
});

it('splits a script into its statements', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(fn () => DB::unprepared(match (engine()) {
        Engine::Postgres => "create index orders_email on orders (email); comment on index orders_email is 'Lookups; by email'",
        default => 'create index orders_email on orders (email); create index orders_status on orders (status);',
    }));

    expect(verdicts($analysis))->toBe(match (engine()) {
        Engine::Postgres => ['unsafe index'],
        Engine::MySql, Engine::MariaDb => ['note index', 'note index'],
        Engine::Sqlite => [],
    });
});

it('stops at a write through another connection, which it would not follow', function () {
    Schema::connection('other')->create('audit', fn (Blueprint $table) => $table->string('event'));

    $analysis = $this->analyze(function () {
        DB::connection('other')->table('audit')->insert(['event' => 'migrated']);
        Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
    });

    expect(verdicts($analysis))->toBe(['warning interrupted'])
        ->and($analysis->findings[0]->message)->toBe('The migration writes through the connection [other], which the analysis does not follow: the rest of the migration was not analyzed.')
        ->and(DB::connection('other')->table('audit')->count())->toBe(0);
});

it('stops at a write through a connection the migration opens', function () {
    config(['database.connections.reporting' => Database::connection()]);

    $analysis = $this->analyze(fn () => DB::connection('reporting')->statement('create table report_runs (id integer)'));

    expect(verdicts($analysis))->toBe(['warning interrupted'])
        ->and(Schema::connection('reporting')->hasTable('report_runs'))->toBeFalse();
});

it('reports the interruption even when the migration catches it', function () {
    Schema::connection('other')->create('audit', fn (Blueprint $table) => $table->string('event'));

    $analysis = $this->analyze(function () {
        try {
            DB::connection('other')->table('audit')->insert(['event' => 'migrated']);
        } catch (Throwable) {
        }
    });

    expect(verdicts($analysis))->toBe(['warning interrupted']);
});

it('stops at a reconnection, which would send the rest for real', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        DB::reconnect();
        DB::table('orders')->delete();
    });

    expect(verdicts($analysis))->toBe(['warning interrupted'])
        ->and(DB::table('orders')->count())->toBe(6);
});

it('stops a migration that would never end without its writes', function () {
    $this->table('orders', rows: 6);

    $analysis = $this->analyze(function () {
        while (DB::table('orders')->where('status', 'new')->exists()) {
            DB::table('orders')->where('status', 'new')->limit(2)->update(['status' => 'archived']);
        }
    });

    expect(verdicts($analysis))->toBe(['unsafe data-change', 'warning interrupted'])
        ->and($analysis->statements)->toHaveCount(1000)
        ->and($analysis->findings[0]->repeats)->toBe(999);
});

it('reports where a migration throws', function () {
    $analysis = $this->analyze(fn () => throw new RuntimeException("The import file is missing.\nCheck storage/imports."));

    expect(verdicts($analysis))->toBe(['warning interrupted'])
        ->and($analysis->findings[0]->message)->toBe('The analysis stopped where the migration threw RuntimeException (The import file is missing): the rest of the migration was not analyzed.');
});

it('keeps jobs, notifications and HTTP calls for the real run', function () {
    Bus::fake();
    Notification::fake();
    Http::fake();

    $this->migration('2030_01_01_000001_notify', <<<'PHP'
            public function up(): void
            {
                dispatch(new \StrictMigrations\Tests\Fixtures\RecalculateTotals);
                \Illuminate\Support\Facades\Notification::route('mail', 'ops@example.com')->notify(new \StrictMigrations\Tests\Fixtures\MigrationCompleted);
                \Illuminate\Support\Facades\Http::post('https://hooks.example.com/migrated');
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull();
    Bus::assertDispatchedTimes(RecalculateTotals::class, 1);
    Notification::assertSentOnDemandTimes(MigrationCompleted::class, 1);
    Http::assertSentCount(1);
});

it('leaves the connection as it found it', function () {
    $this->table('orders', rows: 6);
    $pdo = DB::connection()->getPdo();

    $this->analyze(fn () => DB::table('orders')->delete());
    DB::table('orders')->where('id', 1)->delete();

    expect(DB::connection()->getPdo())->toBe($pdo)
        ->and(DB::table('orders')->count())->toBe(5);
});
