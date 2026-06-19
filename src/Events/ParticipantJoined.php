<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Events;

use RoundlyConsulting\Messages\Models\Participant;

final class ParticipantJoined
{
    public function __construct(
        public readonly Participant $participant,
    ) {}
}
