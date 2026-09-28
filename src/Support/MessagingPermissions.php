<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * Centralises role-based authorization for group threads. Enforcement is config-gated
 * and skipped entirely for direct (1:1) threads, which are intentionally roleless.
 */
final class MessagingPermissions
{
    public static function enabled(): bool
    {
        return config('messages.permissions.enabled') === true;
    }

    /** Whether enforcement applies to this thread at all. */
    public static function enforces(Thread $thread): bool
    {
        return self::enabled() && ! $thread->is_direct;
    }

    /** A manager may add/remove participants, rename, archive, and moderate others' messages. */
    public static function canManage(Thread $thread, Model $actor): bool
    {
        if (! self::enforces($thread)) {
            return true;
        }

        return $thread->roleOf($actor)?->canManage() === true;
    }

    /**
     * Only the owner may promote or demote participants, so an admin can neither hand out
     * admin rights nor take them from a peer.
     */
    public static function canSetRoles(Thread $thread, Model $actor): bool
    {
        if (! self::enforces($thread)) {
            return true;
        }

        return $thread->roleOf($actor)?->isOwner() === true;
    }

    public static function canTransferOwnership(Thread $thread, Model $actor): bool
    {
        if (! self::enforces($thread)) {
            return true;
        }

        return $thread->roleOf($actor)?->isOwner() === true;
    }

    /** Anyone may delete their own message; managers may delete anyone's. */
    public static function canDeleteMessage(Thread $thread, Model $actor, Message $message): bool
    {
        if (self::isAuthor($message, $actor)) {
            return true;
        }

        return self::canManage($thread, $actor);
    }

    /**
     * Only the author may edit a message. Unlike deleting, this is never delegated to managers
     * and never switched off by `messages.permissions.enabled`: rewording someone else's
     * message puts words in their mouth.
     */
    public static function canEditMessage(Message $message, Model $actor): bool
    {
        return self::isAuthor($message, $actor);
    }

    public static function authorizeManage(Thread $thread, Model $actor, string $action): void
    {
        if (! self::canManage($thread, $actor)) {
            throw UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Admin, $action);
        }
    }

    public static function authorizeSetRole(Thread $thread, Model $actor): void
    {
        if (! self::canSetRoles($thread, $actor)) {
            throw UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Owner, 'change participant roles');
        }
    }

    public static function authorizeTransferOwnership(Thread $thread, Model $actor): void
    {
        if (! self::canTransferOwnership($thread, $actor)) {
            throw UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Owner, 'transfer ownership');
        }
    }

    public static function authorizeDeleteMessage(Thread $thread, Model $actor, Message $message): void
    {
        if (! self::canDeleteMessage($thread, $actor, $message)) {
            throw UnauthorizedMessagingAction::for($actor, 'delete this message');
        }
    }

    public static function authorizeEditMessage(Message $message, Model $actor): void
    {
        if (! self::canEditMessage($message, $actor)) {
            throw UnauthorizedMessagingAction::for($actor, 'edit this message');
        }
    }

    private static function isAuthor(Message $message, Model $actor): bool
    {
        return (string) $message->sender_id === (string) $actor->getKey()
            && $message->sender_type === $actor->getMorphClass();
    }
}
