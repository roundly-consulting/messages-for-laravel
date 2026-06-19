<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Models\Message;

final class SendMessage
{
    public function execute(SendMessageData $data): Message
    {
        /** @var Message $message */
        $message = $data->thread->messages()->create([
            'sender_id' => $data->sender?->getKey(),
            'sender_type' => $data->sender?->getMorphClass(),
            'message' => $data->body,
            'type' => $data->type,
            'meta' => $data->meta === [] ? null : $data->meta,
        ]);

        $data->thread->touch('last_activity_at');

        Event::dispatch(new MessageSent($message));

        return $message;
    }
}
