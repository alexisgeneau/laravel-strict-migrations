<?php

declare(strict_types=1);

namespace StrictMigrations\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/** What a migration saw of its session while it ran for real: the dry run runs it first. */
final class Observed
{
    public static ?string $lockTimeout = null;

    public static function lockTimeout(): void
    {
        self::$lockTimeout = self::currentLockTimeout();
    }

    public static function currentLockTimeout(): string
    {
        return (string) DB::scalar(DB::getDriverName() === 'pgsql' ? "select current_setting('lock_timeout')" : 'select @@session.lock_wait_timeout');
    }
}
