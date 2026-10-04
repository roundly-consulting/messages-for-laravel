<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
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

        // The actor must be in the thread; deleting someone else's message also needs manage
        // rights on a group thread. A message whose thread is gone cannot be checked, so it is
        // refused rather than waved through.
        if ($actor !== null) {
            $thread = $message->thread;

            if (! $thread instanceof Thread) {
                throw UnauthorizedMessagingAction::for($actor, 'messages::messages.permissions.actions.delete-message');
            }

            MessagingPermissions::authorizeDeleteMessage($thread, $actor, $message);
        }

        $message->delete();

        Event::dispatch(new MessageDeleted($message));

        return $message;
    }
}
