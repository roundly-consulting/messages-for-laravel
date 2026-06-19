<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

final class AddParticipant
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function execute(AddParticipantData $data): Participant
    {
        if ($data->actor !== null) {
            MessagingPermissions::authorizeManage($data->thread, $data->actor, 'add participants');
        }

        /** @var Participant $participant */
        $participant = $data->thread->participants()->create([
            'participant_id' => $data->participant->getKey(),
            'participant_type' => $data->participant->getMorphClass(),
            'role' => $this->resolveRole($data),
        ]);

        $data->thread->touch('last_activity_at');

        Event::dispatch(new ParticipantJoined($participant));

        $this->maybeSystemMessage($data);

        return $participant;
    }

    /**
     * Group threads always carry a role (defaulting to Member); direct threads stay roleless.
     */
    private function resolveRole(AddParticipantData $data): ?ParticipantRole
    {
        if ($data->thread->is_direct) {
            return null;
        }

        if (config('messages.permissions.enabled') !== true) {
            return $data->role;
        }

        return $data->role ?? ParticipantRole::Member;
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
