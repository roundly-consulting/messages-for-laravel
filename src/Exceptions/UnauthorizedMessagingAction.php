<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;

final class UnauthorizedMessagingAction extends Exception
{
    public static function for(Model $actor, string $action): self
    {
        $message = trans('messages::messages.permissions.unauthorized', [
            'actor' => "{$actor->getMorphClass()}:{$actor->getKey()}",
            'action' => $action,
        ]);

        return new self(is_string($message) ? $message : "Not authorized to {$action}.");
    }

    public static function requiresRole(Model $actor, ParticipantRole $required, string $action): self
    {
        $message = trans('messages::messages.permissions.requires-role', [
            'actor' => "{$actor->getMorphClass()}:{$actor->getKey()}",
            'role' => $required->value,
            'action' => $action,
        ]);

        return new self(is_string($message) ? $message : "Requires the {$required->value} role to {$action}.");
    }
}
