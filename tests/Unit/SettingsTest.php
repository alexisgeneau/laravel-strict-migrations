<?php

declare(strict_types=1);

use StrictMigrations\Settings;

it('reads the configuration, with defaults for what it leaves out', function () {
    $settings = Settings::fromConfig(['mode' => 'warn', 'connections' => 'pgsql', 'small_table_rows' => '500']);

    expect($settings)->toHaveProperties([
        'mode' => 'warn',
        'disabledIn' => ['testing'],
        'checkAfter' => null,
        'checkDown' => false,
        'smallTableRows' => 500,
        'deployment' => 'rolling',
        'lockTimeoutSeconds' => 10,
        'connections' => ['pgsql'],
    ])->and($settings->blocks())->toBeFalse();
});

it('refuses a configuration it cannot follow', function (array $config, string $message) {
    expect(fn () => Settings::fromConfig($config))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [['mode' => 'strict'], 'strict-migrations.mode must be block, warn or off.'],
    [['check_after' => '2026-10-22'], 'strict-migrations.check_after must be a migration timestamp such as 2026_10_22_174512.'],
    [['deployment' => 'blue-green'], 'strict-migrations.deployment must be rolling or maintenance.'],
    [['lock_timeout_seconds' => -1], 'strict-migrations.lock_timeout_seconds must be 0 or more.'],
]);

it('tells the migrations that predate check_after by their timestamp', function () {
    $settings = Settings::fromConfig(['check_after' => '2026_10_22_174512']);

    expect($settings->predates('2026_10_22_174512_add_index'))->toBeTrue()
        ->and($settings->predates('2025_01_01_000000_create_users'))->toBeTrue()
        ->and($settings->predates('2026_10_22_174513_add_index'))->toBeFalse()
        ->and($settings->predates('add_index_without_timestamp'))->toBeFalse()
        ->and(Settings::fromConfig([])->predates('2025_01_01_000000_create_users'))->toBeFalse();
});

it('watches every connection unless told which', function () {
    expect(Settings::fromConfig([])->watches('mysql'))->toBeTrue()
        ->and(Settings::fromConfig(['connections' => ['pgsql']])->watches('mysql'))->toBeFalse();
});
