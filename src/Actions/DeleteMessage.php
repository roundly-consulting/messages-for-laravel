<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Models\Message;

final class DeleteMessage
{
    public function execute(Message $message): Message
    {
        if ($message->trashed()) {
            throw MessageException::alreadyDeleted($message);
        }

        $message->delete();

        Event::dispatch(new MessageDeleted($message));

        return $message;
    }
}
