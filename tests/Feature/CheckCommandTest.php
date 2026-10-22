<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->table('orders', rows: 3);

    $this->migration('2030_01_01_000001_create_invoices', <<<'PHP'
            public function up(): void
            {
                Schema::create('invoices', function (Blueprint $table) {
                    $table->id();
                    $table->string('number');
                });

                Schema::table('invoices', fn (Blueprint $table) => $table->index('number'));
            }
        PHP);

    $this->migration('2030_01_01_000002_archive_orders', <<<'PHP'
            public function up(): void
            {
                DB::table('orders')->where('status', 'new')->update(['status' => 'archived']);
            }
        PHP);

    $this->migration('2030_01_01_000003_drop_notes', <<<'PHP'
            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('notes'));
            }
        PHP);
});

it('checks the pending migrations without running any', function () {
    $outcome = $this->command('migrate:check');

    expect($outcome->status)->toBe(1)
        ->and($outcome->output)
        ->toMatch('/2030_01_01_000001_create_invoices \.+ OK/')
        ->toMatch('/2030_01_01_000002_archive_orders \.+ UNSAFE/')
        ->toMatch('/2030_01_01_000003_drop_notes \.+ UNSAFE/')
        ->toContain('3 migrations checked, 2 unsafe.')
        ->toContain('Tables were assumed large.')
        ->and(Schema::hasTable('invoices'))->toBeFalse()
        ->and(Schema::hasTable('migrations'))->toBeFalse()
        ->and(DB::table('orders')->where('status', 'archived')->count())->toBe(0);
});

it('measures the tables when asked to', function () {
    $outcome = $this->command('migrate:check', ['--sizes' => true]);

    expect($outcome->output)
        ->toMatch('/2030_01_01_000002_archive_orders \.+ WARN/')
        ->toContain('orders: 3 rows, under the 5-row threshold')
        ->toContain('3 migrations checked, 1 unsafe.')
        ->not->toContain('Tables were assumed large.');
});

it('succeeds when nothing is unsafe', function () {
    $outcome = $this->command('migrate:check', ['--path' => "{$this->migrations}/2030_01_01_000001_create_invoices.php"]);

    expect($outcome->status)->toBe(0)
        ->and($outcome->output)->toContain('1 migration checked, 0 unsafe.');
});

it('skips the migrations that ran and the ones older than check_after', function () {
    config(['strict-migrations.check_after' => '2030_01_01_000001']);
    $this->command('migrate', ['--path' => "{$this->migrations}/2030_01_01_000002_archive_orders.php", '--force' => true]);

    $pending = $this->command('migrate:check');
    $all = $this->command('migrate:check', ['--all' => true]);

    expect($pending->output)
        ->not->toContain('2030_01_01_000001_create_invoices')
        ->not->toContain('2030_01_01_000002_archive_orders')
        ->toContain('1 migration checked, 1 unsafe.')
        ->and($all->output)->toContain('3 migrations checked');
});

it('says when there is nothing to check', function () {
    config(['strict-migrations.check_after' => '2030_12_31_000000']);

    $outcome = $this->command('migrate:check');

    expect($outcome->status)->toBe(0)
        ->and($outcome->output)->toContain('No migration to check.');
});

it('reports a migration marked as assured', function () {
    $this->migration('2030_01_01_000004_drop_total', <<<'PHP'
            public function up(): void
            {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('total'));
            }
        PHP, "#[SafetyAssured('total unused since the June release')]");

    $outcome = $this->command('migrate:check', ['--path' => "{$this->migrations}/2030_01_01_000004_drop_total.php"]);

    expect($outcome->status)->toBe(0)
        ->and($outcome->output)->toMatch('/2030_01_01_000004_drop_total total unused since the June release \.+ ASSURED/');
});

it('writes the findings as JSON', function () {
    $outcome = $this->command('migrate:check', ['--format' => 'json']);
    $report = json_decode($outcome->output, true, flags: JSON_THROW_ON_ERROR);

    expect($outcome->status)->toBe(1)
        ->and($report['unsafe'])->toBe(2)
        ->and($report['warnings'])->toBe(0)
        ->and(array_column($report['migrations'], 'migration'))->toBe(['2030_01_01_000001_create_invoices', '2030_01_01_000002_archive_orders', '2030_01_01_000003_drop_notes'])
        ->and($report['migrations'][2]['findings'][0])->toMatchArray([
            'severity' => 'unsafe',
            'kind' => 'deploy',
            'rule' => 'drop-column',
            'repeats' => 0,
        ]);
});

it('writes the findings as GitHub annotations', function () {
    $outcome = $this->command('migrate:check', ['--format' => 'github']);
    $lines = array_values(array_filter(explode("\n", $outcome->output)));

    expect($outcome->status)->toBe(1)
        ->and($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith("::error file={$this->migrations}/2030_01_01_000002_archive_orders.php,title=Unsafe migration (data-change)::")
        ->and($lines[1])->toContain('Dropping orders.notes breaks the code that still reads or writes it while the deployment runs.%0A%0A');
});

it('refuses an unknown format', function () {
    $outcome = $this->command('migrate:check', ['--format' => 'xml']);

    expect($outcome->status)->toBe(2)
        ->and($outcome->output)->toContain('The format must be console, github or json.');
});
