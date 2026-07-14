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
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class MessagesServiceProvider extends PackageServiceProvider
{
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
            ->publishesStubs(
                __DIR__.'/Http/Resources',
                app_path('Http/Resources/Messages'),
                'messages-resources',
            )
            ->publishesStubs(
                __DIR__.'/Notifications',
                app_path('Notifications/Messages'),
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
                'New threads' => self::publicity(),
                'Roles' => config('messages.permissions.enabled') === true ? 'ENFORCED' : 'OFF',
                'System messages' => config('messages.system-messages.enabled') === true ? 'ON' : 'OFF',
                'Notifications' => self::notifications(),
                'Broadcasting' => config('messages.broadcasting.enabled') === true ? 'ON' : 'OFF',
                'Preview length' => self::intValue('messages.preview.length', 120).' chars',
                'Prune retention' => self::intValue('messages.prune.days', 90).' days',
                'Attachments' => self::attachments(),
                'Attachment disk' => self::presence('messages.media.disk', 'MEDIA DEFAULT'),
                'Accepted types' => self::listSize('messages.media.accepted_mime_types', 'mime type', 'ANY'),
                'Max attachment size' => self::bytes(),
                'Responsive widths' => self::listSize('messages.media.responsive_widths', 'width', 'MEDIA DEFAULT'),
                'Warm variants on send' => config('messages.media.warm_on_send') === true ? 'ON' : 'OFF',
                'Attachment cleanup' => config('messages.media.cleanup_on_force_delete') === true
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
        $public = config('messages.publicity.public-by-default') === true;
        $open = config('messages.publicity.everyone-can-join') === true;

        return sprintf(
            '%s (%s)',
            $public ? 'PUBLIC' : 'PRIVATE',
            $open ? 'anyone may join' : 'invite only',
        );
    }

    private static function notifications(): string
    {
        if (config('messages.notifications.enabled') !== true) {
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
