<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

/**
 * Hand ownership of a group thread from the current owner to another participant. The
 * outgoing owner is demoted to admin so they keep management rights.
 */
final class TransferOwnership
{
    public function execute(Thread $thread, Model $currentOwner, Model $newOwner): Participant
    {
        MessagingPermissions::authorizeTransferOwnership($thread, $currentOwner);

        $current = $this->participantFor($thread, $currentOwner);
        $next = $this->participantFor($thread, $newOwner);

        $current->forceFill(['role' => ParticipantRole::Admin])->save();
        $next->forceFill(['role' => ParticipantRole::Owner])->save();

        return $next;
    }

    private function participantFor(Thread $thread, Model $model): Participant
    {
        $participant = $thread->participants()
            ->whereMorphedTo('participant', $model)
            ->first();

        if (! $participant instanceof Participant) {
            throw ParticipationException::notAParticipant($model);
        }

        return $participant;
    }
}
