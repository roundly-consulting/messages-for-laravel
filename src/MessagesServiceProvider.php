<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Support\ServiceProvider;

final class MessagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/messages.php', 'messages');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/messages.php' => config_path('messages.php'),
            ], 'messages-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'messages-migrations');
        }
    }
}
