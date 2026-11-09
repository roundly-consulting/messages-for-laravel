<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The names of the channels the package broadcasts on — without the `private-` prefix, the way
 * `routes/channels.php` patterns and `Echo.private()` spell them.
 *
 * @internal
 */
final class BroadcastChannels
{
    /** Characters every broadcaster accepts in a channel name segment (Pusher's set, minus `.`). */
    private const string SAFE_ALIAS = '/^[A-Za-z0-9_\-=@,;]+$/';

    /**
     * The per-participant channel new private threads are announced on, for a stored morph type
     * and key. The template's placeholders:
     *
     * - `{name}`  — the lowercased class basename; two classes sharing a basename share it.
     * - `{alias}` — the morph alias when the type is one, otherwise a short hash of the type.
     * - `{type}`  — deprecated: the full morph type, lowercased, `\` as `.` (a class name).
     * - `{id}`    — the participant's key.
     */
    public static function participant(string $type, string $id): string
    {
        return str_replace(
            search: ['{name}', '{alias}', '{type}', '{id}'],
            replace: [
                str($type)->classBasename()->lower()->toString(),
                self::alias($type),
                str($type)->replace('\\', '.')->lower()->toString(),
                $id,
            ],
            subject: MessagesConfig::threadPerParticipantChannel(),
        );
    }

    /** The per-participant channel of a participating model. */
    public static function forParticipant(Model $participant): string
    {
        return self::participant($participant->getMorphClass(), (string) $participant->getKey());
    }

    /**
     * A collision-free, channel-safe stand-in for a morph type that never carries a class name:
     * the morph alias itself when the type is a morph-map alias made of channel-safe
     * characters, otherwise the first 16 hex characters of its SHA-256.
     */
    public static function alias(string $type): string
    {
        if (Relation::getMorphedModel($type) !== null && preg_match(self::SAFE_ALIAS, $type) === 1) {
            return $type;
        }

        return substr(hash('sha256', $type), 0, 16);
    }

    /**
     * The channels a thread's messages, participant changes and typing signals go out on.
     *
     * @return list<string>
     */
    public static function thread(int|string $id): array
    {
        return array_values(array_unique(array_map(
            static fn (string $template): string => str_replace('{id}', (string) $id, $template),
            self::threadTemplates(),
        )));
    }

    /**
     * The configured thread channel templates, deduplicated (both default to
     * `messaging.thread.{id}`).
     *
     * @return list<string>
     */
    public static function threadTemplates(): array
    {
        return array_values(array_unique([
            MessagesConfig::messagesChannel(),
            MessagesConfig::participantsChannel(),
        ]));
    }
}
