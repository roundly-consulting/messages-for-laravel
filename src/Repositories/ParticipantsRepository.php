<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

final class ParticipantsRepository
{
    public function addParticipantToThread(Thread $thread, Model $participant): Participant
    {
        $created = $thread->participants()->create([
            'participant_id' => $participant->getKey(),
            'participant_type' => $participant->getMorphClass(),
        ]);

        $thread->touch('last_activity_at');

        return $created;
    }
}
