<?php

declare(strict_types=1);

namespace StrictMigrations\Tests;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Analysis\Run;
use StrictMigrations\Analysis\Subject;
use StrictMigrations\Database\Engine;
use StrictMigrations\Database\Server;
use StrictMigrations\Guard;
use StrictMigrations\StrictMigrationsServiceProvider;
use Throwable;

abstract class TestCase extends Orchestra
{
    /** Where the migrations written by a test live. */
    protected string $migrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrations = sys_get_temp_dir().'/strict-migrations-'.getmypid().'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->migrations);

        if (Server::of(DB::connection())?->engine !== engine()) {
            $this->fail('STRICT_MIGRATIONS_TEST_DB names another engine than the server the tests connect to.');
        }

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->migrations);

        parent::tearDown();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [StrictMigrationsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', Database::connection());
        $app['config']->set('database.connections.other', Database::connection());
        $app['config']->set('strict-migrations.disabled_in', []);
        $app['config']->set('strict-migrations.small_table_rows', 5);
        $app['config']->set('strict-migrations.lock_timeout_retry_seconds', 0);
    }

    /**
     * A table already in production, outside any migration: id, customer_id, email, status,
     * total, notes and timestamps, with as many rows as asked. Above 5 rows, it is large.
     *
     * @param  Closure(Blueprint): void|null  $columns
     */
    protected function table(string $name, int $rows = 0, ?Closure $columns = null): void
    {
        Schema::create($name, function (Blueprint $table) use ($columns): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('new');
            $table->decimal('total', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            if ($columns !== null) {
                $columns($table);
            }
        });

        if ($rows > 0) {
            DB::table($name)->insert(array_map(fn (int $row): array => ['customer_id' => $row, 'email' => "customer{$row}@example.com"], range(1, $rows)));
        }
    }

    /**
     * Analyzes a migration whose up() is the callback, the way the migrator would before running it.
     *
     * @param  Closure(): mixed  $up
     */
    protected function analyze(Closure $up, bool $withinTransaction = true, bool $measures = true, ?Run $run = null, ?string $connection = null): Analysis
    {
        $migration = new class extends Migration {};
        $migration->withinTransaction = $withinTransaction;
        $connection = DB::connection($connection);
        $connection->useDefaultSchemaGrammar();

        return $this->app->make(Guard::class)->analyze(new Subject(
            '2030_01_01_000000_under_analysis',
            __FILE__,
            $migration,
            'up',
            $connection,
            $connection->getSchemaGrammar()->supportsSchemaTransactions() && $withinTransaction,
            $up,
        ), $run ?? new Run, $measures);
    }

    /**
     * Writes a migration file holding the class body given.
     *
     * @param  string  $attributes  Attributes of the migration class, such as #[SafetyAssured('...')].
     */
    protected function migration(string $name, string $body, string $attributes = ''): string
    {
        $path = "{$this->migrations}/{$name}.php";

        File::put($path, <<<PHP
            <?php

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\DB;
            use Illuminate\\Support\\Facades\\Schema;
            use StrictMigrations\\Attributes\\SafetyAssured;

            return new {$attributes} class extends Migration
            {
            {$body}
            };

            PHP);

        return $path;
    }

    /**
     * Runs an artisan command on the migrations written by the test.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): Outcome
    {
        try {
            $status = Artisan::call($command, ['--path' => $this->migrations, '--realpath' => true, ...$parameters]);

            return new Outcome($status, Artisan::output());
        } catch (Throwable $exception) {
            return new Outcome(null, Artisan::output(), $exception);
        }
    }

    /** @return list<string> The migrations recorded as run. */
    protected function ran(): array
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->orderBy('id')->pluck('migration')->all() : [];
    }

    private function resetDatabase(): void
    {
        $connection = DB::connection();

        match (engine()) {
            Engine::Postgres => $connection->unprepared('drop schema if exists public cascade; create schema public'),
            Engine::MySql, Engine::MariaDb => (function (): void {
                Schema::dropAllViews();
                Schema::dropAllTables();
            })(),
            Engine::Sqlite => null,
        };
    }
}
