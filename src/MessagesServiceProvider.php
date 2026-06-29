<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Messages\Commands\PruneMessagesCommand;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Listeners\NotifyParticipantsOfNewMessage;
use RoundlyConsulting\Messages\Listeners\WarmMessageMediaVariants;

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

        Event::listen(MessageSent::class, NotifyParticipantsOfNewMessage::class);
        Event::listen(MessageSent::class, WarmMessageMediaVariants::class);

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

            $this->publishes([
                __DIR__.'/../src/Http/Resources' => app_path('Http/Resources/Messages'),
            ], 'messages-resources');

            $this->publishes([
                __DIR__.'/../src/Notifications' => app_path('Notifications/Messages'),
            ], 'messages-notifications');
        }
    }
}
