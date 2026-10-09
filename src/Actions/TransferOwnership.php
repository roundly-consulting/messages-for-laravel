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
 *
 * Ownership moves only from the participant who holds it — with roles enforced or not, since
 * the roles stay the thread's data for the day enforcement is switched on — so a thread never
 * ends up with two owners. A direct thread has no roles, so it has no ownership to move.
 */
final class TransferOwnership
{
    public function execute(Thread $thread, Model $currentOwner, Model $newOwner): Participant
    {
        MessagingPermissions::authorizeTransferOwnership($thread, $currentOwner);

        if ($thread->is_direct) {
            throw ParticipationException::directThreadHasNoRoles();
        }

        $owner = $thread->getConnection()->transaction(function () use ($thread, $currentOwner, $newOwner): Participant {
            // Serialised per thread, as adds are: two transfers at once must not both read the
            // same owner and leave two.
            $thread->newQueryWithoutScopes()->whereKey($thread->getKey())->lockForUpdate()->first();

            $current = $this->participantFor($thread, $currentOwner);
            $next = $this->participantFor($thread, $newOwner);

            if ($current->role !== ParticipantRole::Owner) {
                throw ParticipationException::notTheOwner($currentOwner);
            }

            // Handing ownership to yourself changes nothing. (Demoting and re-promoting two
            // copies of one row used to leave it an admin, and the thread ownerless.)
            if ($current->is($next)) {
                return $current;
            }

            $current->forceFill(['role' => ParticipantRole::Admin])->save();
            $next->forceFill(['role' => ParticipantRole::Owner])->save();

            return $next;
        });

        // A loaded relation would still answer `participationOf()` with the old roles.
        $thread->unsetRelation('participants');

        return $owner;
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
