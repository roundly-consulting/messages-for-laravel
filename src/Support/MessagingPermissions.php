<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Centralises authorization for actor-checked operations.
 *
 * Two layers: an actor must first be a **participant** of the thread — always, whatever the
 * config and thread type; roles never make an outsider an insider. Then, on a group thread with
 * `messages.permissions.enabled`, their **role** decides. Direct (1:1) threads are roleless, so
 * there — and with roles off — every participant is a peer.
 */
final class MessagingPermissions
{
    public static function enabled(): bool
    {
        return Config::boolean('messages.permissions.enabled', true);
    }

    /** Whether enforcement applies to this thread at all. */
    public static function enforces(Thread $thread): bool
    {
        return self::enabled() && ! $thread->is_direct;
    }

    /** A manager may add/remove participants, rename, archive, and moderate others' messages. */
    public static function canManage(Thread $thread, Model $actor): bool
    {
        $participation = $thread->participationOf($actor);

        if ($participation === null) {
            return false;
        }

        return ! self::enforces($thread) || $participation->role?->canManage() === true;
    }

    /**
     * Removing someone else takes manage rights and — with roles enforced — a role above the
     * target's: the owner removes admins and members, an admin removes members only, so an
     * admin can neither remove the owner nor a fellow admin.
     */
    public static function canRemove(Thread $thread, Model $actor, Participant $target): bool
    {
        if (! self::canManage($thread, $actor)) {
            return false;
        }

        if (! self::enforces($thread)) {
            return true;
        }

        return $thread->roleOf($actor)?->outranks($target->role ?? ParticipantRole::Member) === true;
    }

    /**
     * Only the owner may promote or demote participants, so an admin can neither hand out
     * admin rights nor take them from a peer.
     */
    public static function canSetRoles(Thread $thread, Model $actor): bool
    {
        return self::isOwnerOrPeer($thread, $actor);
    }

    public static function canTransferOwnership(Thread $thread, Model $actor): bool
    {
        return self::isOwnerOrPeer($thread, $actor);
    }

    /** A participant may delete their own message; managers may delete anyone's. */
    public static function canDeleteMessage(Thread $thread, Model $actor, Message $message): bool
    {
        if (self::isAuthor($message, $actor)) {
            return $thread->participationOf($actor) !== null;
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
        $thread = $message->thread;

        return self::isAuthor($message, $actor)
            && $thread instanceof Thread
            && $thread->participationOf($actor) !== null;
    }

    public static function authorizeManage(Thread $thread, Model $actor, string $action): void
    {
        if (! self::canManage($thread, $actor)) {
            throw UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Admin, $action);
        }
    }

    public static function authorizeRemove(Thread $thread, Model $actor, Participant $target): void
    {
        if (! self::canRemove($thread, $actor, $target)) {
            throw UnauthorizedMessagingAction::for($actor, 'remove this participant');
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

    /**
     * The owner on an enforced group thread; any participant where roles do not apply.
     */
    private static function isOwnerOrPeer(Thread $thread, Model $actor): bool
    {
        $participation = $thread->participationOf($actor);

        if ($participation === null) {
            return false;
        }

        return ! self::enforces($thread) || $participation->role?->isOwner() === true;
    }

    private static function isAuthor(Message $message, Model $actor): bool
    {
        return (string) $message->sender_id === (string) $actor->getKey()
            && $message->sender_type === $actor->getMorphClass();
    }
}
