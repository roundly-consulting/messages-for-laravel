<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\DataTransferObjects\RemoveParticipantData;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * A participant removes themselves. Always allowed — no role is needed to leave.
 */
final class LeaveThread
{
    public function __construct(
        private readonly RemoveParticipant $removeParticipant,
    ) {}

    public function execute(Thread $thread, Model $participant): void
    {
        $this->removeParticipant->execute(new RemoveParticipantData(
            thread: $thread,
            participant: $participant,
            actor: $participant,
        ));
    }
}
