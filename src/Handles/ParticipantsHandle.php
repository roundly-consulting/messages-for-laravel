<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\AddParticipant;
use RoundlyConsulting\Messages\Actions\LeaveThread;
use RoundlyConsulting\Messages\Actions\RemoveParticipant;
use RoundlyConsulting\Messages\Actions\SetParticipantRole;
use RoundlyConsulting\Messages\Actions\TransferOwnership;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\DataTransferObjects\RemoveParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SetParticipantRoleData;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * The participants of one thread — `Messages::thread($thread)->participants()`.
 *
 * Each method takes the participating model (a user, a company, …) or its {@see Participant}
 * row. A row from another thread is refused.
 */
final readonly class ParticipantsHandle
{
    public function __construct(
        private MessagesManager $manager,
        private Thread $thread,
    ) {}

    /**
     * Add a participant. Group threads give them `$role` (Member when omitted and roles are
     * enforced); direct threads stay roleless. Adding others needs manage rights for `$by`.
     */
    public function add(Model $participant, ?ParticipantRole $role = null, ?Model $by = null): Participant
    {
        $participant = $this->resolve($participant);

        return $this->manager->perform(
            new MessagingCall(MessagingOperation::AddParticipant, thread: $this->thread, participant: $participant, actor: $by, role: $role),
            AddParticipant::class,
            fn (AddParticipant $action): Participant => $action->execute(
                new AddParticipantData($this->thread, $participant, $role, $by),
            ),
        );
    }

    /** Remove a participant. Removing someone else needs manage rights for `$by`. */
    public function remove(Model $participant, ?Model $by = null): void
    {
        $participant = $this->resolve($participant);

        $this->manager->perform(
            new MessagingCall(MessagingOperation::RemoveParticipant, thread: $this->thread, participant: $participant, actor: $by),
            RemoveParticipant::class,
            function (RemoveParticipant $action) use ($participant, $by): void {
                $action->execute(new RemoveParticipantData($this->thread, $participant, $by));
            },
        );
    }

    /** The participant leaves the thread. Always allowed. */
    public function leave(Model $participant): void
    {
        $participant = $this->resolve($participant);

        $this->manager->perform(
            new MessagingCall(MessagingOperation::Leave, thread: $this->thread, participant: $participant, actor: $participant),
            LeaveThread::class,
            function (LeaveThread $action) use ($participant): void {
                $action->execute($this->thread, $participant);
            },
        );
    }

    /** Promote or demote a participant of a group thread. Needs manage rights for `$by`. */
    public function setRole(Model $participant, ParticipantRole $role, ?Model $by = null): Participant
    {
        $participant = $this->resolve($participant);

        return $this->manager->perform(
            new MessagingCall(MessagingOperation::SetRole, thread: $this->thread, participant: $participant, actor: $by, role: $role),
            SetParticipantRole::class,
            fn (SetParticipantRole $action): Participant => $action->execute(
                new SetParticipantRoleData($this->thread, $participant, $role, $by),
            ),
        );
    }

    /**
     * Hand ownership from the current owner to another participant; the outgoing owner is
     * demoted to admin. Returns the new owner's participant row.
     */
    public function transferOwnership(Model $from, Model $to): Participant
    {
        $from = $this->resolve($from);
        $to = $this->resolve($to);

        return $this->manager->perform(
            new MessagingCall(MessagingOperation::TransferOwnership, thread: $this->thread, participant: $to, actor: $from, role: ParticipantRole::Owner),
            TransferOwnership::class,
            fn (TransferOwnership $action): Participant => $action->execute($this->thread, $from, $to),
        );
    }

    /**
     * The participating model behind the argument: a {@see Participant} row of this thread
     * resolves to its model; a row of another thread — or one whose model is gone — is refused.
     *
     * @internal
     *
     * @throws ParticipationException
     */
    public function resolve(Model $participant): Model
    {
        if (! $participant instanceof Participant) {
            return $participant;
        }

        if ((string) $participant->thread_id !== (string) $this->thread->getKey()) {
            throw ParticipationException::inAnotherThread($participant);
        }

        return $participant->participant ?? throw ParticipationException::participantMissing($participant);
    }
}
