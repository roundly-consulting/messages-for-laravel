<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\Events\ThreadRead;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Support\MessageModel;

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

        // Asked of the database, not of `$data->thread`: `latestMessage` answers from the
        // instance's own `last_message_id`, which is stale on a thread loaded before the latest
        // sends — and would park the pointer on an older message than the reader just saw.
        $latest = MessageModel::class()::newestMessageIdIn($participant->thread_id);

        // The pointer is the read position; `read_at` records when, and stands in for the
        // pointer only once its message has been pruned.
        $participant->forceFill([
            'read_at' => now(),
            'last_read_message_id' => $latest,
        ])->save();

        Event::dispatch(new ThreadRead($participant));

        return $participant;
    }
}
