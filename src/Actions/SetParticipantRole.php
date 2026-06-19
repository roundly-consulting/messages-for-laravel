<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use RoundlyConsulting\Messages\DataTransferObjects\SetParticipantRoleData;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

/**
 * Promote or demote a participant within a group thread. Owner-level transfers go through
 * {@see TransferOwnership} instead.
 */
final class SetParticipantRole
{
    public function execute(SetParticipantRoleData $data): Participant
    {
        if ($data->actor !== null) {
            MessagingPermissions::authorizeManage($data->thread, $data->actor, 'change participant roles');
        }

        $participant = $data->thread
            ->participants()
            ->whereMorphedTo('participant', $data->participant)
            ->first();

        if (! $participant instanceof Participant) {
            throw ParticipationException::notAParticipant($data->participant);
        }

        $participant->forceFill(['role' => $data->role])->save();

        return $participant;
    }
}
