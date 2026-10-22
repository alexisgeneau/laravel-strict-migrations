<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StrictMigrations\Database\Engine;
use StrictMigrations\Exceptions\LockTimeoutExceeded;
use StrictMigrations\Tests\Fixtures\Observed;

beforeEach(function () {
    if (engine() === Engine::Sqlite) {
        $this->markTestSkipped('SQLite has no lock timeout.');
    }

    Observed::$lockTimeout = null;
    $this->table('orders', rows: 3);
    config(['strict-migrations.lock_timeout_seconds' => 1]);
});

/**
 * Another session reads orders in a transaction it leaves open: its lock keeps the migrations
 * from altering the table. The server ends the session once it has been idle that long, if
 * given: MySQL counts in whole seconds.
 */
function holdOrders(?int $milliseconds = null): void
{
    $other = DB::connection('other');

    if ($milliseconds !== null) {
        $other->statement(engine() === Engine::Postgres
            ? "set idle_in_transaction_session_timeout = '{$milliseconds}ms'"
            : 'set session wait_timeout = '.(int) ceil($milliseconds / 1000));
    }

    $other->beginTransaction();
    $other->table('orders')->count();
}

$addCurrency = <<<'PHP'
        public function up(): void
        {
            \StrictMigrations\Tests\Fixtures\Observed::lockTimeout();
            Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
        }
    PHP;

it('bounds the wait of every migration, then restores the session', function () use ($addCurrency) {
    config(['strict-migrations.lock_timeout_seconds' => 10]);
    $before = Observed::currentLockTimeout();
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Observed::$lockTimeout)->toBe(engine() === Engine::Postgres ? '10s' : '10')
        ->and(Observed::currentLockTimeout())->toBe($before);
});

it('runs the migration again once the transaction holding its lock has ended', function () use ($addCurrency) {
    config(['strict-migrations.lock_timeout_retries' => 3]);
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);
    holdOrders(milliseconds: 1500);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(Schema::hasColumn('orders', 'currency'))->toBeTrue()
        ->and($this->ran())->toBe(['2030_01_01_000001_add_currency'])
        ->and($outcome->output)->toContain('Another transaction holds a lock the migration needs: attempt 2 of 4 in 0 s.');
});

it('gives up after the retries, having applied nothing', function () use ($addCurrency) {
    config(['strict-migrations.lock_timeout_retries' => 1]);
    $before = Observed::currentLockTimeout();
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);
    holdOrders();

    $outcome = $this->command('migrate');
    DB::connection('other')->rollBack();

    expect($outcome->exception)->toBeInstanceOf(LockTimeoutExceeded::class)
        ->and($outcome->exception->getMessage())->toBe('2030_01_01_000001_add_currency could not take its locks within 1 s, 2 attempts: another transaction holds them. Nothing of the migration was applied; run the migrations again once that transaction has ended.')
        ->and(Schema::hasColumn('orders', 'currency'))->toBeFalse()
        ->and($this->ran())->toBe([])
        ->and(Observed::currentLockTimeout())->toBe($before);
});

it('does not run again a migration that applied statements outside a transaction', function () {
    config(['strict-migrations.lock_timeout_retries' => 3]);
    $this->migration('2030_01_01_000001_add_currency', <<<'PHP'
            public $withinTransaction = false;

            public function up(): void
            {
                Schema::create('currencies', fn (Blueprint $table) => $table->string('code', 3)->primary());
                Schema::table('orders', fn (Blueprint $table) => $table->string('currency', 3)->nullable());
            }
        PHP);
    holdOrders();

    $outcome = $this->command('migrate');
    DB::connection('other')->rollBack();

    expect($outcome->exception)->toBeInstanceOf(LockTimeoutExceeded::class)
        ->and($outcome->exception->getMessage())->toContain('Statements of the migration had already run outside a transaction, so it was not retried')
        ->and(Schema::hasTable('currencies'))->toBeTrue()
        ->and($outcome->output)->not->toContain('attempt 2');
});

it('lifts the lock timeout for a concurrent build, which a timeout would leave invalid', function () {
    $this->migration('2030_01_01_000001_index_customers', <<<'PHP'
            public $withinTransaction = false;

            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->index('customer_id')->online());
            }
        PHP);
    holdOrders(milliseconds: 1500);

    $outcome = $this->command('migrate');

    expect($outcome->exception)->toBeNull()
        ->and(DB::scalar("select indisvalid from pg_index where indexrelid = 'orders_customer_id_index'::regclass"))->toBeTrue();
})->skip(engine() !== Engine::Postgres, 'PostgreSQL only.');

it('leaves the session alone when lock_timeout_seconds is 0', function () use ($addCurrency) {
    config(['strict-migrations.lock_timeout_seconds' => 0]);
    $before = Observed::currentLockTimeout();
    $this->migration('2030_01_01_000001_add_currency', $addCurrency);

    $this->command('migrate');

    expect(Observed::$lockTimeout)->toBe($before);
});
