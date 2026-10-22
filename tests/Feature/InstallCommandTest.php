<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(fn () => File::delete(config_path('strict-migrations.php')));
afterEach(fn () => File::delete(config_path('strict-migrations.php')));

it('publishes the configuration, checking only the migrations written from now on', function () {
    $this->travelTo('2026-10-22 17:45:12');

    $this->artisan('strict-migrations:install')
        ->expectsOutputToContain('the migrations created after 2026_10_22_174512 are checked')
        ->assertSuccessful();

    expect(File::get(config_path('strict-migrations.php')))
        ->toContain("'check_after' => env('STRICT_MIGRATIONS_CHECK_AFTER', '2026_10_22_174512'),")
        ->and(require config_path('strict-migrations.php'))->toHaveKeys(['mode', 'check_after', 'small_table_rows', 'lock_timeout_seconds']);
});

it('keeps a configuration that exists, unless forced', function () {
    File::put(config_path('strict-migrations.php'), '<?php return [];');

    $this->artisan('strict-migrations:install')->assertFailed();
    expect(File::get(config_path('strict-migrations.php')))->toBe('<?php return [];');

    $this->artisan('strict-migrations:install', ['--force' => true])->assertSuccessful();
    expect(File::get(config_path('strict-migrations.php')))->toContain('check_after');
});
