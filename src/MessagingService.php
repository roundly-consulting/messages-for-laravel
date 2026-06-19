<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use RoundlyConsulting\Messages\Repositories\MessagesRepository;
use RoundlyConsulting\Messages\Repositories\ParticipantsRepository;
use RoundlyConsulting\Messages\Repositories\ThreadsRepository;

final class MessagingService
{
    public function messages(): MessagesRepository
    {
        return resolve(MessagesRepository::class);
    }

    public function threads(): ThreadsRepository
    {
        return resolve(ThreadsRepository::class);
    }

    public function participants(): ParticipantsRepository
    {
        return resolve(ParticipantsRepository::class);
    }
}
