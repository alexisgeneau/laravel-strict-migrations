<?php

declare(strict_types=1);

namespace StrictMigrations\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecalculateTotals implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
