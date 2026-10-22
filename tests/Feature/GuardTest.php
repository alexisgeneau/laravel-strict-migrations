<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;
use StrictMigrations\Exceptions\UnsafeMigration;
use StrictMigrations\Tests\Fixtures\Observed;

beforeEach(function () {
    $this->table('orders', rows: 6);
});

$addCurrency = <<<'PHP'
        public function up(): void
        {
            Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        }
    PHP;

$dropNotes = <<<'PHP'
        public function up(): void
        {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes'));
        }

        public function down(): void
        {
            Schema::table('orders', fn (Blueprint $table) => $table->text('notes')->nullable());
        }
    PHP;

it('refuses an unsafe migration before any of its statements runs', function () use ($addCurrency, $dropNotes) {
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeInstanceOf(UnsafeMigration::class)
        ->and($outcome->exception->getMessage())->toBe('2030_01_01_000002_drop_notes was refused before any of its statements ran.')
        ->and($this->ran())->toBe(['2030_01_01_000001_add_currency'])
        ->and(Schema::hasColumn('orders', 'currency'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'notes'))->toBeTrue()
        ->and($outcome->output)
        ->toContain('2030_01_01_000001_add_currency')
        ->toContain('UNSAFE  2030_01_01_000002_drop_notes')
        ->toContain('⨯ Dropping orders.notes breaks the code that still reads or writes it while the deployment runs.')
        ->toContain('Stop using notes first')
        ->toMatch('/2030_01_01_000002_drop_notes \.+ REFUSED/');
});

it('runs a safe migration without a word', function () use ($addCurrency) {
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and($this->ran())->toBe(['2030_01_01_000001_add_currency'])
        ->and($outcome->output)->not->toContain('UNSAFE')->not->toContain('WARN');
});

it('reports a warning and runs the migration', function () {
    DB::table('orders')->where('id', '>', 3)->delete();
    $this->migration('2030_01_01_000001_archive', <<<'PHP'
            public function up(): void
            {
                DB::table('orders')->where('status', 'new')->update(['status' => 'archived']);
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and($this->ran())->toBe(['2030_01_01_000001_archive'])
        ->and(DB::table('orders')->where('status', 'archived')->count())->toBe(3)
        ->and($outcome->output)->toContain('WARN  2030_01_01_000001_archive')->toContain('orders: 3 rows, under the 5-row threshold');
});

it('shows the notes in verbose mode only', function () {
    config(['strict-migrations.deployment' => 'maintenance']);

    $this->migration('2030_01_01_000001_drop_notes', <<<'PHP'
            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes'));
            }
        PHP);
    $quiet = $this->command('migrate');

    $this->migration('2030_01_01_000002_drop_total', <<<'PHP'
            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('total'));
            }
        PHP);
    $verbose = $this->command('migrate', ['-v' => true]);

    expect($quiet->output)->not->toContain('NOTE')
        ->and($verbose->output)->toContain('NOTE  2030_01_01_000002_drop_total')->toContain('· Dropping orders.total breaks the code');
});

it('runs an unsafe migration in warn mode, after reporting it', function () use ($dropNotes) {
    config(['strict-migrations.mode' => 'warn']);
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'notes'))->toBeFalse()
        ->and($outcome->output)->toContain('UNSAFE  2030_01_01_000002_drop_notes')->toContain('Running anyway: strict-migrations.mode is warn.');
});

it('stays out of the way when it is off', function (array $config) use ($dropNotes) {
    config($config);
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'notes'))->toBeFalse()
        ->and($outcome->output)->not->toContain('UNSAFE');
})->with([
    'mode off' => [['strict-migrations.mode' => 'off']],
    'disabled in this environment' => [['strict-migrations.disabled_in' => ['testing']]],
    'another connection watched' => [['strict-migrations.connections' => ['other']]],
    'a migration older than check_after' => [['strict-migrations.check_after' => '2030_01_01_000002']],
]);

it('checks the migrations newer than check_after', function () use ($dropNotes) {
    config(['strict-migrations.check_after' => '2030_01_01_000001']);
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    expect($this->command('migrate')->exception)->toBeInstanceOf(UnsafeMigration::class);
});

it('runs a migration marked as assured, with its reason', function () use ($dropNotes) {
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes, "#[SafetyAssured('notes unused since the March release')]");

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'notes'))->toBeFalse()
        ->and($outcome->output)->toContain('ASSURED  2030_01_01_000002_drop_notes notes unused since the March release');
});

it('runs a statement wrapped in safetyAssured, reporting it as assured', function () {
    $this->migration('2030_01_01_000002_drop_notes', <<<'PHP'
            public function up(): void
            {
                Schema::safetyAssured(fn () => Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes')));
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'notes'))->toBeFalse()
        ->and($outcome->output)->toContain('ASSURED  2030_01_01_000002_drop_notes')->not->toContain('Stop using notes first');
});

it('only vouches for the statements inside safetyAssured', function () {
    $this->migration('2030_01_01_000002_drop_columns', <<<'PHP'
            public function up(): void
            {
                Schema::safetyAssured(fn () => Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes')));
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('total'));
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeInstanceOf(UnsafeMigration::class)
        ->and($outcome->exception->analysis->findings[0]->severity->value)->toBe('assured')
        ->and($outcome->exception->analysis->findings[1]->severity->value)->toBe('unsafe')
        ->and(Schema::hasColumn('orders', 'notes'))->toBeTrue();
});

it('lets a run change the tables it created', function () {
    $this->migration('2030_01_01_000001_create_invoices', <<<'PHP'
            public function up(): void
            {
                Schema::create('invoices', function (Blueprint $table) {
                    $table->id();
                    $table->string('number');
                    $table->text('notes')->nullable();
                });
            }
        PHP);
    $this->migration('2030_01_01_000002_rework_invoices', <<<'PHP'
            public function up(): void
            {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index('number');
                    $table->dropColumn('notes');
                    $table->renameColumn('number', 'reference');
                });
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and($this->ran())->toHaveCount(2)
        ->and(Schema::hasColumn('invoices', 'reference'))->toBeTrue();
});

it('does not judge what it only pretends to run', function () use ($dropNotes) {
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    $outcome = $this->command('migrate', ['--pretend' => true]);

    expect($outcome->exception)->toBeNull()
        ->and($outcome->output)->toContain('drop')->not->toContain('UNSAFE');
});

it('leaves rollbacks alone unless check_down is on', function () {
    $this->migration('2030_01_01_000003_restore_notes', <<<'PHP'
            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->string('reference')->nullable());
            }

            public function down(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('reference'));
            }
        PHP);
    $this->command('migrate');

    config(['strict-migrations.check_down' => true]);
    $checked = $this->command('migrate:rollback');

    config(['strict-migrations.check_down' => false]);
    $unchecked = $this->command('migrate:rollback');

    expect($checked->exception)->toBeInstanceOf(UnsafeMigration::class)
        ->and($unchecked->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'reference'))->toBeFalse();
});

it('puts the findings in the exception when there is no output to report them', function () use ($dropNotes) {
    $this->migration('2030_01_01_000002_drop_notes', $dropNotes);

    app('migrator')->getRepository()->createRepository();

    expect(fn () => app('migrator')->run([$this->migrations]))->toThrow(
        UnsafeMigration::class,
        '2030_01_01_000002_drop_notes was refused before any of its statements ran. Dropping orders.notes breaks the code that still reads or writes it while the deployment runs.',
    );
});

it('stays quiet on migrate:fresh, which creates every table it changes', function () {
    $this->migration('2030_01_01_000001_create_invoices', <<<'PHP'
            public function up(): void
            {
                Schema::create('invoices', function (Blueprint $table) {
                    $table->id();
                    $table->string('number');
                    $table->text('notes')->nullable();
                });
            }
        PHP);
    $this->migration('2030_01_01_000002_rework_invoices', <<<'PHP'
            public function up(): void
            {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index('number');
                    $table->dropColumn('notes');
                });
            }
        PHP);
    $this->command('migrate');

    $outcome = $this->command('migrate:fresh');

    expect($outcome->exception)->toBeNull()
        ->and($outcome->output)->not->toContain('UNSAFE')->not->toContain('WARN')
        ->and($this->ran())->toBe(['2030_01_01_000001_create_invoices', '2030_01_01_000002_rework_invoices']);
});

it('keeps the transaction the migrator opens on PostgreSQL, which undoes a migration that fails', function () {
    Observed::$transactionLevel = null;
    $this->migration('2030_01_01_000001_add_currency', <<<'PHP'
            public function up(): void
            {
                \StrictMigrations\Tests\Fixtures\Observed::$transactionLevel = DB::transactionLevel();
                Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
                DB::table('orders')->insert(['id' => 1, 'email' => 'duplicate@example.com']);
            }
        PHP);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeInstanceOf(QueryException::class)
        ->and(Observed::$transactionLevel)->toBe(1)
        ->and(Schema::hasColumn('orders', 'currency'))->toBeFalse()
        ->and($this->ran())->toBe([])
        ->and(DB::transactionLevel())->toBe(0);
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only: the migrator wraps no other engine in a transaction.');
