<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;

final class UnauthorizedMessagingAction extends Exception
{
    /**
     * @param  string  $action  A `messages::messages.permissions.actions.*` key, read in the
     *                          current locale — or a plain phrase, used as it is.
     */
    public static function for(Model $actor, string $action): self
    {
        $action = self::phrase($action);

        $message = trans('messages::messages.permissions.unauthorized', [
            'actor' => "{$actor->getMorphClass()}:{$actor->getKey()}",
            'action' => $action,
        ]);

        return new self(is_string($message) ? $message : "Not authorized to {$action}.");
    }

    /**
     * @param  string  $action  A `messages::messages.permissions.actions.*` key, read in the
     *                          current locale — or a plain phrase, used as it is.
     */
    public static function requiresRole(Model $actor, ParticipantRole $required, string $action): self
    {
        $action = self::phrase($action);
        $role = self::phrase('messages::messages.permissions.roles.'.$required->value, $required->value);

        $message = trans('messages::messages.permissions.requires-role', [
            'actor' => "{$actor->getMorphClass()}:{$actor->getKey()}",
            'role' => $role,
            'action' => $action,
        ]);

        return new self(is_string($message) ? $message : "Requires the {$role} role to {$action}.");
    }

    /**
     * The line a translation key names in the current locale. A key with no line — a caller's
     * own phrase rather than a key — falls back to the default, or to the text itself.
     */
    private static function phrase(string $key, ?string $default = null): string
    {
        $translated = trans($key);

        return is_string($translated) && $translated !== $key ? $translated : ($default ?? $key);
    }
}
