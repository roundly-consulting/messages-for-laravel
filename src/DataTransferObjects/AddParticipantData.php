<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class AddParticipantData
{
    /**
     * @param  bool  $creatingThread  @internal Set by {@see StartThread} while it seats a new
     *                                thread's participants — the only time a direct thread takes
     *                                one. Every other add to a direct thread is refused.
     */
    public function __construct(
        public Thread $thread,
        public Model $participant,
        public ?ParticipantRole $role = null,
        public ?Model $actor = null,
        public bool $creatingThread = false,
    ) {}
}
