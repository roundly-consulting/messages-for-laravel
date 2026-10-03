<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Closure;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Commands\PruneMessagesCommand;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Listeners\NotifyParticipantsOfNewMessage;
use RoundlyConsulting\Messages\Listeners\WarmMessageMediaVariants;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Support\ParticipantModel;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class MessagesServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('messages')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                PruneMessagesCommand::class,
            ])
            // Host-owned copies in the app namespace — never the package's own classes, which
            // under app/ would break PSR-4 and never load, so edits to them changed nothing.
            ->publishesStubs(
                __DIR__.'/../stubs/Http/Resources/ThreadResource.php.stub',
                app_path('Http/Resources/Messages/ThreadResource.php'),
                'messages-resources',
            )
            ->publishesStubs(
                __DIR__.'/../stubs/Http/Resources/MessageResource.php.stub',
                app_path('Http/Resources/Messages/MessageResource.php'),
                'messages-resources',
            )
            ->publishesStubs(
                __DIR__.'/../stubs/Http/Resources/ParticipantResource.php.stub',
                app_path('Http/Resources/Messages/ParticipantResource.php'),
                'messages-resources',
            )
            ->publishesStubs(
                __DIR__.'/../stubs/Notifications/NewMessageNotification.php.stub',
                app_path('Notifications/Messages/NewMessageNotification.php'),
                'messages-notifications',
            )
            // This package carries private conversations. The section reports the
            // *shape* of the configuration — switches, counts and bounds — and never
            // renders host topology (the attachment disk) or any list a host wrote.
            // Message bodies, subjects and participants are user content and appear
            // nowhere.
            ->contributesToAbout(static fn (): array => [
                'Thread model' => class_basename(ThreadModel::class()),
                'Message model' => class_basename(MessageModel::class()),
                'Participant model' => class_basename(ParticipantModel::class()),
                // Surfaced deliberately: a non-bigint id cannot be held by another package's
                // `morphs()` column on a strict engine, so a host that has flipped this needs
                // to see it without reading a migration.
                'Key type' => KeyType::fromConfig('messages.primary_key_type')->value,
                'New threads' => self::publicity(),
                'Roles' => Config::boolean('messages.permissions.enabled', true) ? 'ENFORCED' : 'OFF',
                'System messages' => Config::boolean('messages.system-messages.enabled') ? 'ON' : 'OFF',
                'Notifications' => self::notifications(),
                'Broadcasting' => Config::boolean('messages.broadcasting.enabled') ? 'ON' : 'OFF',
                'Preview length' => self::orInvalid(static fn (): string => MessagesConfig::previewLength().' chars'),
                'Prune retention' => self::orInvalid(static fn (): string => MessagesConfig::pruneDays().' days'),
                'Attachments' => self::orInvalid(static fn (): string => sprintf(
                    '%s bucket (%s)',
                    MessagesConfig::attachmentsBucket(),
                    MessagesConfig::attachmentsVisibility(),
                )),
                'Attachment disk' => self::orInvalid(static fn (): string => MessagesConfig::disk() === null ? 'MEDIA DEFAULT' : 'SET'),
                'Accepted types' => self::orInvalid(static fn (): string => self::count(MessagesConfig::acceptedMimeTypes(), 'mime type', 'ANY')),
                'Max attachment size' => self::orInvalid(static fn (): string => ($max = MessagesConfig::maxFileSize()) === null ? 'MEDIA DEFAULT' : $max.' B'),
                'Responsive widths' => self::orInvalid(static fn (): string => self::count(MessagesConfig::responsiveWidths() ?? [], 'width', 'MEDIA DEFAULT')),
                'Warm variants on send' => Config::boolean('messages.media.warm_on_send', true) ? 'ON' : 'OFF',
                'Attachment cleanup' => Config::boolean('messages.media.cleanup_on_force_delete', true)
                    ? 'ON FORCE DELETE'
                    : 'OFF',
                'Signed URL lifetime' => self::orInvalid(static fn (): string => ($minutes = MessagesConfig::configuredTemporaryUrlLifetime()) === null ? 'MEDIA DEFAULT' : $minutes.' min'),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(MessagesManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations key `last_read_message_id` off the toolkit's `ownerKey` macro, so it
        // must exist before they run. Registration is idempotent — the toolkit guards it with
        // `hasMacro()`.
        $this->registerBlueprintMacros();

        Event::listen(MessageSent::class, NotifyParticipantsOfNewMessage::class);
        Event::listen(MessageSent::class, WarmMessageMediaVariants::class);
    }

    /**
     * A strict read rendered for `about`, or `INVALID` when the setting is broken — so
     * `php artisan about` still works on a misconfigured host while every real read throws.
     *
     * @param  Closure(): string  $read
     */
    private static function orInvalid(Closure $read): string
    {
        try {
            return $read();
        } catch (InvalidConfigurationException) {
            return 'INVALID';
        }
    }

    /**
     * The size of a configured list, never its entries.
     *
     * @param  list<mixed>  $values
     */
    private static function count(array $values, string $noun, string $absent): string
    {
        return $values === [] ? $absent : sprintf('%d %s(s)', count($values), $noun);
    }

    private static function publicity(): string
    {
        $public = Config::boolean('messages.publicity.public-by-default');
        $open = Config::boolean('messages.publicity.everyone-can-join');

        return sprintf(
            '%s (%s)',
            $public ? 'PUBLIC' : 'PRIVATE',
            $open ? 'anyone may join' : 'invite only',
        );
    }

    private static function notifications(): string
    {
        if (! Config::boolean('messages.notifications.enabled')) {
            return 'OFF';
        }

        return self::orInvalid(static fn (): string => sprintf(
            'ON (%s, %d channel(s))',
            class_basename(MessagesConfig::notification()),
            count(MessagesConfig::notificationChannels()),
        ));
    }
}
