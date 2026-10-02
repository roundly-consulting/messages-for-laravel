<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Commands\PruneMessagesCommand;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Listeners\NotifyParticipantsOfNewMessage;
use RoundlyConsulting\Messages\Listeners\WarmMessageMediaVariants;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\ParticipantModel;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
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
                'Preview length' => self::intValue('messages.preview.length', 120).' chars',
                'Prune retention' => self::intValue('messages.prune.days', 90).' days',
                'Attachments' => self::attachments(),
                'Attachment disk' => self::presence('messages.media.disk', 'MEDIA DEFAULT'),
                'Accepted types' => self::listSize('messages.media.accepted_mime_types', 'mime type', 'ANY'),
                'Max attachment size' => self::bytes(),
                'Responsive widths' => self::listSize('messages.media.responsive_widths', 'width', 'MEDIA DEFAULT'),
                'Warm variants on send' => Config::boolean('messages.media.warm_on_send', true) ? 'ON' : 'OFF',
                'Attachment cleanup' => Config::boolean('messages.media.cleanup_on_force_delete', true)
                    ? 'ON FORCE DELETE'
                    : 'OFF',
                'Signed URL lifetime' => self::signedUrlLifetime(),
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
     * Whether a config key holds a non-empty value — never the value itself. The
     * attachment disk names a host filesystem, so only its presence is reported.
     */
    private static function presence(string $key, string $absent): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? 'SET' : $absent;
    }

    private static function intValue(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The size of a configured list, never its entries.
     */
    private static function listSize(string $key, string $noun, string $absent): string
    {
        $value = config($key);

        if (! is_array($value) || $value === []) {
            return $absent;
        }

        return sprintf('%d %s(s)', count($value), $noun);
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

        $notification = config('messages.notifications.notification');
        $channels = config('messages.notifications.channels');

        return sprintf(
            'ON (%s, %d channel(s))',
            is_string($notification) && $notification !== '' ? class_basename($notification) : 'DEFAULT',
            is_array($channels) ? count($channels) : 0,
        );
    }

    private static function attachments(): string
    {
        $bucket = config('messages.media.attachments_bucket');
        $visibility = config('messages.media.visibility');

        return sprintf(
            '%s bucket (%s)',
            is_string($bucket) && $bucket !== '' ? $bucket : 'attachments',
            is_string($visibility) && $visibility !== '' ? $visibility : 'private',
        );
    }

    private static function bytes(): string
    {
        $max = config('messages.media.max_file_size');

        return is_numeric($max) ? ((int) $max).' B' : 'MEDIA DEFAULT';
    }

    private static function signedUrlLifetime(): string
    {
        $minutes = config('messages.media.temporary_url_lifetime');

        return is_numeric($minutes) ? ((int) $minutes).' min' : 'MEDIA DEFAULT';
    }
}
