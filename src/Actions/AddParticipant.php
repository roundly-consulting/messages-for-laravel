<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Participant;

final class AddParticipant
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function execute(AddParticipantData $data): Participant
    {
        /** @var Participant $participant */
        $participant = $data->thread->participants()->create([
            'participant_id' => $data->participant->getKey(),
            'participant_type' => $data->participant->getMorphClass(),
        ]);

        $data->thread->touch('last_activity_at');

        Event::dispatch(new ParticipantJoined($participant));

        $this->maybeSystemMessage($data);

        return $participant;
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
            body: 'messages::messages.system.participant_joined',
            type: MessageType::System,
            meta: ['participant' => $actor],
        ));
    }
}
