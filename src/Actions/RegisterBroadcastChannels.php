<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\BroadcastChannels;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Register the authorisation callbacks of the package's private channels on the default
 * broadcaster — the opt-in alternative to writing them in `routes/channels.php`:
 *
 * - each thread channel (`messages.broadcasting.messages.channel`,
 *   `messages.broadcasting.participants.channel`) admits the thread's current participants;
 * - the per-participant channel (`messages.broadcasting.threads.per-participant-channel`)
 *   admits only the model it belongs to.
 *
 * The authenticated user is the participating model. A thread template without `{id}` names no
 * thread and gets no callback, and the deprecated `{type}` placeholder (dots in one segment)
 * cannot be matched by a channel pattern — the broadcaster refuses both channels.
 */
final readonly class RegisterBroadcastChannels
{
    /**
     * @param  list<string>  $guards  the guards to authenticate the user with; empty = the default
     */
    public function execute(array $guards = []): void
    {
        $options = $guards === [] ? [] : ['guards' => $guards];

        foreach (BroadcastChannels::threadTemplates() as $template) {
            $keys = self::placeholders($template);

            if (! in_array('id', $keys, true)) {
                continue;
            }

            Broadcast::channel(
                $template,
                static fn (mixed $user, mixed ...$values): bool => self::participatesIn($user, self::bind($keys, $values)['id'] ?? null),
                $options,
            );
        }

        $template = MessagesConfig::threadPerParticipantChannel();
        $keys = self::placeholders($template);

        Broadcast::channel(
            $template,
            static fn (mixed $user, mixed ...$values): bool => $user instanceof Model
                && self::fill($template, self::bind($keys, $values)) === BroadcastChannels::forParticipant($user),
            $options,
        );
    }

    /**
     * The placeholder names of a template, in order — the order the broadcaster passes their
     * values to the callback.
     *
     * @return list<string>
     */
    private static function placeholders(string $template): array
    {
        preg_match_all('/\{(.*?)\}/', $template, $matches);

        return $matches[1];
    }

    /**
     * Pair the placeholder names with the values the broadcaster extracted. A value the
     * broadcaster bound to anything but a scalar (a host's explicit route binding) is dropped,
     * so the check it feeds fails closed.
     *
     * @param  list<string>  $keys
     * @param  array<int|string, mixed>  $values
     * @return array<string, string>
     */
    private static function bind(array $keys, array $values): array
    {
        $bound = [];

        foreach (array_values($values) as $index => $value) {
            if (isset($keys[$index]) && is_scalar($value)) {
                $bound[$keys[$index]] = (string) $value;
            }
        }

        return $bound;
    }

    /**
     * The channel the template names for these values; null when one is missing.
     *
     * @param  array<string, string>  $values
     */
    private static function fill(string $template, array $values): ?string
    {
        foreach (self::placeholders($template) as $key) {
            if (! isset($values[$key])) {
                return null;
            }

            $template = str_replace('{'.$key.'}', $values[$key], $template);
        }

        return $template;
    }

    private static function participatesIn(mixed $user, ?string $id): bool
    {
        if (! $user instanceof Model || $id === null || ! self::isKey($id)) {
            return false;
        }

        $thread = ThreadModel::class()::query()->whereKey($id)->first();

        return $thread instanceof Thread && $thread->participationOf($user) !== null;
    }

    /**
     * Whether the value can be a thread key at all — checked before the query, so a junk id
     * never reaches a strict engine's uuid column (PostgreSQL throws on it).
     */
    private static function isKey(string $id): bool
    {
        return match (KeyType::fromConfig('messages.primary_key_type')) {
            KeyType::BigInt => ctype_digit($id),
            KeyType::Uuid => Str::isUuid($id),
            KeyType::Ulid => Str::isUlid($id),
        };
    }
}
