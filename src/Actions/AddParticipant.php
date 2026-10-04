<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Support\MessagingPermissions;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Add a participant to a thread — find-or-add: someone who is already in the thread gets their
 * existing row back, unchanged (no event, no system message, no role change; use
 * {@see SetParticipantRole} for that).
 *
 * With an actor:
 *  - adding **yourself** is a join, allowed only on a thread with `everyone_can_join` (direct
 *    threads never are); a member who joins again is a no-op;
 *  - adding **someone else** needs manage rights, and — with roles enforced — a role above
 *    member needs the owner.
 *
 * Ownership is never granted here once a thread has an owner, and never by an actor: it changes
 * hands only through {@see TransferOwnership}. (A new thread's first participant becomes its
 * owner through {@see StartThread}, which calls this without an actor.)
 */
final class AddParticipant
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function execute(AddParticipantData $data): Participant
    {
        // No unique index backs this up — a soft-deleted row (someone who left) must not block
        // them rejoining, and a partial index is not portable to MySQL. So adds are serialised
        // per thread instead: the row lock makes two concurrent adds of one model see each other.
        $participant = $data->thread->getConnection()->transaction(function () use ($data): Participant {
            $data->thread->newQueryWithoutScopes()->whereKey($data->thread->getKey())->lockForUpdate()->first();

            $existing = $data->thread
                ->participants()
                ->whereMorphedTo('participant', $data->participant)
                ->first();

            $this->authorize($data, $existing instanceof Participant);

            if ($existing instanceof Participant) {
                return $existing;
            }

            /** @var Participant */
            return $data->thread->participants()->create([
                'participant_id' => $data->participant->getKey(),
                'participant_type' => $data->participant->getMorphClass(),
                'role' => $this->resolveRole($data),
            ]);
        });

        if (! $participant->wasRecentlyCreated) {
            return $participant;
        }

        $data->thread->touch('last_activity_at');

        Event::dispatch(new ParticipantJoined($participant));

        $this->maybeSystemMessage($data);

        return $participant;
    }

    private function authorize(AddParticipantData $data, bool $alreadyIn): void
    {
        if ($data->role === ParticipantRole::Owner && ($data->actor !== null || $this->hasOwner($data))) {
            throw ParticipationException::ownershipOnlyByTransfer();
        }

        if ($data->actor === null) {
            return;
        }

        if ($this->isSelf($data)) {
            if ($alreadyIn) {
                return;
            }

            if (! $data->thread->everyone_can_join) {
                throw UnauthorizedMessagingAction::for($data->actor, 'messages::messages.permissions.actions.join-thread');
            }
        } else {
            MessagingPermissions::authorizeManage($data->thread, $data->actor, 'messages::messages.permissions.actions.add-participants');
        }

        if ($data->role !== null && $data->role !== ParticipantRole::Member) {
            MessagingPermissions::authorizeSetRole($data->thread, $data->actor);
        }
    }

    private function hasOwner(AddParticipantData $data): bool
    {
        return $data->thread->participants()->where('role', ParticipantRole::Owner->value)->exists();
    }

    private function isSelf(AddParticipantData $data): bool
    {
        return $data->actor !== null
            && (string) $data->actor->getKey() === (string) $data->participant->getKey()
            && $data->actor->getMorphClass() === $data->participant->getMorphClass();
    }

    /**
     * Group threads always carry a role (defaulting to Member) — with roles enforced or not, so
     * switching enforcement on later finds every participant ranked; direct threads stay
     * roleless.
     */
    private function resolveRole(AddParticipantData $data): ?ParticipantRole
    {
        if ($data->thread->is_direct) {
            return null;
        }

        return $data->role ?? ParticipantRole::Member;
    }

    private function maybeSystemMessage(AddParticipantData $data): void
    {
        if (! Config::boolean('messages.system-messages.enabled')) {
            return;
        }

        $actor = $data->participant instanceof ParticipatesInMessaging
            ? $data->participant->participateAs()
            : [];

        $this->sendMessage->execute(new SendMessageData(
            thread: $data->thread,
            sender: null,
            body: 'messages::messages.system.participant_joined',
            type: MessageType::System,
            meta: ['participant' => $actor],
        ));
    }
}
