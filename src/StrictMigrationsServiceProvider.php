<?php

declare(strict_types=1);

namespace StrictMigrations;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\ServiceProvider;
use StrictMigrations\Console\CheckCommand;
use StrictMigrations\Console\InstallCommand;
use StrictMigrations\DryRun\DryRun;

final class StrictMigrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/strict-migrations.php', 'strict-migrations');

        $this->app->singleton(DryRun::class);
        $this->app->singleton(LockTimeout::class);

        $this->app->extend('migrator', fn (Migrator $migrator, Application $app): Migrator => $migrator::class === Migrator::class
            ? $this->strict($migrator, $app)
            : $migrator);

        $this->app->bind(StrictMigrator::class, function (Application $app): StrictMigrator {
            $migrator = $app->make('migrator');

            return $migrator instanceof StrictMigrator ? $migrator : $this->strict($migrator, $app);
        });
    }

    public function boot(): void
    {
        $app = $this->app;

        Builder::macro('safetyAssured', function (Closure $callback) use ($app): mixed {
            return $app->make(DryRun::class)->assured($callback);
        });

        $app->make(Dispatcher::class)->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($app): void {
            $app->make(DryRun::class)->guard($event->connection);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/strict-migrations.php' => config_path('strict-migrations.php')], 'strict-migrations-config');

            $this->commands([CheckCommand::class, InstallCommand::class]);
        }
    }

    /** Laravel's migrator rebuilt as a StrictMigrator, with the paths packages already registered on it. */
    private function strict(Migrator $migrator, Application $app): StrictMigrator
    {
        $strict = new StrictMigrator(
            $migrator->getRepository(),
            $app->make('db'),
            $migrator->getFilesystem(),
            $app->make(Dispatcher::class),
            $app->make(Guard::class),
            $app->make(LockTimeout::class),
        );

        foreach ($migrator->paths() as $path) {
            $strict->path($path);
        }

        return $strict;
    }
}
