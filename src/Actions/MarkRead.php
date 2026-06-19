<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\Events\ThreadRead;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Participant;

final class MarkRead
{
    public function execute(MarkReadData $data): Participant
    {
        $participant = $data->thread
            ->participants()
            ->whereMorphedTo('participant', $data->participant)
            ->first();

        if (! $participant instanceof Participant) {
            throw ParticipationException::notAParticipant($data->participant);
        }

        $latest = $data->thread->latestMessage()->first();

        $participant->forceFill([
            'read_at' => now(),
            'last_read_message_id' => $latest?->getKey(),
        ])->save();

        Event::dispatch(new ThreadRead($participant));

        return $participant;
    }
}
