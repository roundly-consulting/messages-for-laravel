<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Events;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Thread;

final class ParticipantLeft
{
    public function __construct(
        public readonly Thread $thread,
        public readonly Model $participant,
    ) {}
}
