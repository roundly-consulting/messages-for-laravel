<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class RemoveParticipantData
{
    /**
     * @param  Model|null  $actor  who removes them; null skips the permission check
     */
    public function __construct(
        public Thread $thread,
        public Model $participant,
        public ?Model $actor = null,
    ) {}
}
