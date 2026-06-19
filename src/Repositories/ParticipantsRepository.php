<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\AddParticipant;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

final class ParticipantsRepository
{
    public function __construct(
        private readonly AddParticipant $addParticipant,
    ) {}

    public function addParticipantToThread(Thread $thread, Model $participant): Participant
    {
        return $this->addParticipant->execute(new AddParticipantData($thread, $participant));
    }
}
