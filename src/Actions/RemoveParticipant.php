<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\ParticipantLeft;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Participant;

final class RemoveParticipant
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function execute(AddParticipantData $data): void
    {
        $participant = $data->thread
            ->participants()
            ->whereMorphedTo('participant', $data->participant)
            ->first();

        if (! $participant instanceof Participant) {
            throw ParticipationException::notAParticipant($data->participant);
        }

        $participant->delete();

        $data->thread->touch('last_activity_at');

        Event::dispatch(new ParticipantLeft($data->thread, $data->participant));

        $this->maybeSystemMessage($data);
    }

    private function maybeSystemMessage(AddParticipantData $data): void
    {
        if (config('messages.system-messages.enabled') !== true) {
            return;
        }

        $actor = $data->participant instanceof ParticipatesInMessaging
            ? $data->participant->participateAs()
            : [];

        $this->sendMessage->execute(new SendMessageData(
            thread: $data->thread,
            sender: null,
            body: 'messages::messages.system.participant_left',
            type: MessageType::System,
            meta: ['participant' => $actor],
        ));
    }
}
