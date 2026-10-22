<?php

declare(strict_types=1);

namespace StrictMigrations\Tests\Fixtures;

use Illuminate\Notifications\Notification;

final class MigrationCompleted extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
