<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict readers for the package's non-boolean settings.
 *
 * A setting that is not set — absent, null, or blank like a host's `KEY=` — takes its default
 * (or, for an optional setting, none). Anything else unusable — `ninety` for a retention window,
 * `pubic` for a visibility, an array for a disk or broadcast channel — throws
 * {@see InvalidConfigurationException} naming the key, so a typo never quietly falls back
 * (a junk prune window used to read as 0: prune every message).
 *
 * @internal
 */
final class MessagesConfig
{
    public const string VISIBILITY_PRIVATE = 'private';

    public const string VISIBILITY_PUBLIC = 'public';

    /** The default retention window of `messages:prune`, in days (at least 1). */
    public static function pruneDays(): int
    {
        return Config::integer('messages.prune.days', 90, 1);
    }

    public static function previewLength(): int
    {
        return Config::integer('messages.preview.length', 120, 1);
    }

    public static function attachmentsBucket(): string
    {
        return self::string('messages.media.attachments_bucket', 'attachments');
    }

    /** `private` or `public`. */
    public static function attachmentsVisibility(): string
    {
        return Config::oneOf(
            'messages.media.visibility',
            [self::VISIBILITY_PRIVATE, self::VISIBILITY_PUBLIC],
            self::VISIBILITY_PRIVATE,
        );
    }

    /** The explicit attachment disk, or null to choose one by visibility. */
    public static function disk(): ?string
    {
        return self::optionalString('messages.media.disk');
    }

    public static function privateDisk(): string
    {
        return self::string('messages.media.private_disk', 'local');
    }

    /**
     * The accepted mime types; an empty list (the default) accepts any file.
     *
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return self::stringList('messages.media.accepted_mime_types', self::unlessBlank(config('messages.media.accepted_mime_types')) ?? []);
    }

    /** The attachment size cap in bytes, or null for media-library's own limit. */
    public static function maxFileSize(): ?int
    {
        return self::unlessBlank(config('messages.media.max_file_size')) === null
            ? null
            : Config::integer('messages.media.max_file_size', 1, 1);
    }

    /**
     * The responsive width ladder, or null for media-library's default ladder.
     *
     * @return list<int>|null
     */
    public static function responsiveWidths(): ?array
    {
        $key = 'messages.media.responsive_widths';
        $widths = self::unlessBlank(config($key));

        if ($widths === null) {
            return null;
        }

        if (! is_array($widths) || ! array_is_list($widths)) {
            throw self::notAList($key, 'positive integers', $widths);
        }

        $clean = [];

        foreach ($widths as $width) {
            // Validated under the setting's own key, so the message names it.
            $clean[] = Config::for([$key => $width])->integer($key, 1, 1);
        }

        return array_values(array_unique($clean));
    }

    /** The configured signed-URL lifetime in minutes, or null for media-library's default. */
    public static function configuredTemporaryUrlLifetime(): ?int
    {
        return self::unlessBlank(config('messages.media.temporary_url_lifetime')) === null
            ? null
            : Config::integer('messages.media.temporary_url_lifetime', 5, 1);
    }

    /** Lifetime, in minutes, of a signed attachment URL. */
    public static function temporaryUrlLifetime(): int
    {
        return self::configuredTemporaryUrlLifetime()
            ?? Config::integer('media.temporary_url_default_lifetime', 5, 1);
    }

    /**
     * The notification class sent to participants.
     *
     * @return class-string<Notification>
     */
    public static function notification(): string
    {
        $key = 'messages.notifications.notification';
        $class = self::unlessBlank(config($key)) ?? NewMessageNotification::class;

        if (! is_string($class) || ! is_subclass_of($class, Notification::class)) {
            throw InvalidConfigurationException::notAnImplementation($key, Notification::class, $class);
        }

        return $class;
    }

    /**
     * The notification channels; `['database']` when unset.
     *
     * @return list<string>
     */
    public static function notificationChannels(): array
    {
        return self::stringList('messages.notifications.channels', self::unlessBlank(config('messages.notifications.channels')) ?? ['database']);
    }

    public static function threadPublicChannel(): string
    {
        return self::string('messages.broadcasting.threads.public-channel', 'messaging');
    }

    public static function threadPerParticipantChannel(): string
    {
        return self::string('messages.broadcasting.threads.per-participant-channel', 'messaging.participant.{name}.{id}');
    }

    public static function threadCreatedEvent(): string
    {
        return self::string('messages.broadcasting.threads.events.created', 'messaging.thread.created');
    }

    public static function participantsChannel(): string
    {
        return self::string('messages.broadcasting.participants.channel', 'messaging.thread.{id}');
    }

    /** The broadcast name of a participant model event; `''` for an event the package never broadcasts. */
    public static function participantEvent(string $event): string
    {
        return match ($event) {
            'created' => self::string('messages.broadcasting.participants.events.created', 'messaging.participant.joined'),
            'updated' => self::string('messages.broadcasting.participants.events.updated', 'messaging.participant.read'),
            'trashed' => self::string('messages.broadcasting.participants.events.trashed', 'messaging.participant.left'),
            'restored' => self::string('messages.broadcasting.participants.events.restored', 'messaging.participant.joined'),
            'deleted' => self::string('messages.broadcasting.participants.events.deleted', 'messaging.participant.left'),
            default => '',
        };
    }

    public static function typingEvent(): string
    {
        return self::string('messages.broadcasting.typing.event', 'messaging.participant.typing');
    }

    public static function messagesChannel(): string
    {
        return self::string('messages.broadcasting.messages.channel', 'messaging.thread.{id}');
    }

    /** The broadcast name of a message model event; `''` for an event the package never broadcasts. */
    public static function messageEvent(string $event): string
    {
        return match ($event) {
            'created' => self::string('messages.broadcasting.messages.events.created', 'messaging.message.sent'),
            'updated' => self::string('messages.broadcasting.messages.events.updated', 'messaging.message.updated'),
            'trashed' => self::string('messages.broadcasting.messages.events.trashed', 'messaging.message.unsent'),
            'restored' => self::string('messages.broadcasting.messages.events.restored', 'messaging.message.restored'),
            'deleted' => self::string('messages.broadcasting.messages.events.deleted', 'messaging.message.unsent'),
            default => '',
        };
    }

    private static function string(string $key, string $default): string
    {
        return self::optionalString($key) ?? $default;
    }

    private static function optionalString(string $key): ?string
    {
        $value = self::unlessBlank(config($key));

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * A raw config value, with a blank string (`''` or whitespace — a host's `KEY=`) read as
     * null: not set, exactly like an absent key.
     */
    private static function unlessBlank(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(string $key, mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw self::notAList($key, 'non-empty strings', $values);
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw self::notAList($key, 'non-empty strings', $value);
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private static function notAList(string $key, string $of, mixed $value): InvalidConfigurationException
    {
        $given = match (true) {
            $value === '' => "''",
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => var_export($value, true),
            default => get_debug_type($value),
        };

        return new InvalidConfigurationException("Configuration value [{$key}] must be a list of {$of}, [{$given}] given.");
    }
}
