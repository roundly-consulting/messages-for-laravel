<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

final class DeleteMessage
{
    public function execute(Message $message, ?Model $actor = null): Message
    {
        if ($message->trashed()) {
            throw MessageException::alreadyDeleted($message);
        }

        // Deleting someone else's message requires manage rights on a group thread.
        if ($actor !== null && $message->thread instanceof Thread) {
            MessagingPermissions::authorizeDeleteMessage($message->thread, $actor, $message);
        }

        $message->delete();

        Event::dispatch(new MessageDeleted($message));

        return $message;
    }
}
