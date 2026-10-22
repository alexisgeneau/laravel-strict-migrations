<?php

declare(strict_types=1);

namespace StrictMigrations\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'strict-migrations:install')]
final class InstallCommand extends Command
{
    protected $signature = 'strict-migrations:install {--force : Overwrite the configuration file if it exists}';

    protected $description = 'Publish the configuration, so that only the migrations written from now on are checked';

    public function handle(Filesystem $files): int
    {
        $target = $this->laravel->configPath('strict-migrations.php');

        if ($files->exists($target) && ! $this->option('force')) {
            $this->components->warn('config/strict-migrations.php already exists: --force overwrites it.');

            return self::FAILURE;
        }

        $timestamp = Date::now()->format('Y_m_d_His');

        $files->put($target, str_replace(
            "env('STRICT_MIGRATIONS_CHECK_AFTER')",
            "env('STRICT_MIGRATIONS_CHECK_AFTER', '{$timestamp}')",
            $files->get(__DIR__.'/../../config/strict-migrations.php'),
        ));

        $this->components->info("Strict migrations is installed: the migrations created after {$timestamp} are checked.");
        $this->components->bulletList([
            'php artisan migrate refuses an unsafe migration before any of its statements runs, and shows how to write it safely.',
            'php artisan migrate:check checks the pending migrations without running them: run it in CI against your production database engine.',
            'STRICT_MIGRATIONS=warn reports instead of refusing, for a deployment that must go through.',
        ]);

        return self::SUCCESS;
    }
}
