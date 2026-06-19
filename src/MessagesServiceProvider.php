<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\Commands\PruneMessagesCommand;

final class MessagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/messages.php', 'messages');

        $this->app->singleton(MessagesManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'messages');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneMessagesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/messages.php' => config_path('messages.php'),
            ], 'messages-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'messages-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/messages'),
            ], 'messages-translations');
        }
    }
}
