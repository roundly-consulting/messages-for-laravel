<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class AddParticipantData
{
    public function __construct(
        public Thread $thread,
        public Model $participant,
    ) {}
}
