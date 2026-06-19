<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class SetParticipantRoleData
{
    public function __construct(
        public Thread $thread,
        public Model $participant,
        public ParticipantRole $role,
        public ?Model $actor = null,
    ) {}
}
