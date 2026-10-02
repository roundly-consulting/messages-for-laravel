<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use RoundlyConsulting\Messages\DataTransferObjects\SetParticipantRoleData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

/**
 * Promote or demote a participant between admin and member within a group thread.
 *
 * Ownership never moves here — not granted, not taken away, whoever asks: it changes hands only
 * through {@see TransferOwnership}. With roles enforced, only the owner may change roles, so an
 * admin can neither promote a member nor demote a fellow admin. A direct thread has no roles to
 * change.
 */
final class SetParticipantRole
{
    public function execute(SetParticipantRoleData $data): Participant
    {
        if ($data->role === ParticipantRole::Owner) {
            throw ParticipationException::ownershipOnlyByTransfer();
        }

        if ($data->actor !== null) {
            MessagingPermissions::authorizeSetRole($data->thread, $data->actor);
        }

        if ($data->thread->is_direct) {
            throw ParticipationException::directThreadHasNoRoles();
        }

        $participant = $data->thread
            ->participants()
            ->whereMorphedTo('participant', $data->participant)
            ->first();

        if (! $participant instanceof Participant) {
            throw ParticipationException::notAParticipant($data->participant);
        }

        if ($participant->role === ParticipantRole::Owner) {
            throw ParticipationException::ownershipOnlyByTransfer();
        }

        $participant->forceFill(['role' => $data->role])->save();

        return $participant;
    }
}
