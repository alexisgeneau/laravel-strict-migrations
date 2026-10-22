<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * Keeps a migration run for its analysis from dispatching jobs, sending mail and
 * notifications, or calling HTTP services: those would happen again when it runs for real.
 */
final class SideEffects
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withheld(Closure $callback): mixed
    {
        $originals = [];

        foreach ($this->fakes() as $facade => $fake) {
            $original = $facade::getFacadeRoot();

            if (($replacement = $fake($original)) !== null) {
                $originals[$facade] = $original;
                $facade::swap($replacement);
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($originals as $facade => $original) {
                $facade::swap($original);
            }
        }
    }

    /**
     * The fake of each facade bound in the application, made from what the facade stands for:
     * a fake already, when a test faked it.
     *
     * @return array<class-string<Facade>, Closure(mixed): ?object>
     */
    private function fakes(): array
    {
        $fakes = [];

        if ($this->app->bound(Dispatcher::class)) {
            $fakes[Bus::class] = fn (mixed $bus): ?object => $bus instanceof QueueingDispatcher ? new BusFake($bus) : null;
        }

        if ($this->app->bound('queue')) {
            $fakes[Queue::class] = fn (mixed $queue): object => new QueueFake($this->app, [], $queue);
        }

        if ($this->app->bound('mail.manager')) {
            $fakes[Mail::class] = fn (mixed $mail): object => new MailFake($mail instanceof MailManager ? $mail : new MailManager($this->app));
        }

        if ($this->app->bound(ChannelManager::class)) {
            $fakes[Notification::class] = fn (): object => new NotificationFake;
        }

        if (class_exists(Factory::class)) {
            $fakes[Http::class] = fn (): object => (new Factory($this->app->make('events')))->fake();
        }

        return $fakes;
    }
}
