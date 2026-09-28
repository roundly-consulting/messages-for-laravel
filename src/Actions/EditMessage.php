<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\Events\MessageEdited;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Support\MessagingPermissions;

final class EditMessage
{
    public function execute(EditMessageData $data): Message
    {
        if ($data->message->trashed()) {
            throw MessageException::alreadyDeleted($data->message);
        }

        // Only the author may reword a message — managers can delete it, never rewrite it.
        if ($data->actor !== null) {
            MessagingPermissions::authorizeEditMessage($data->message, $data->actor);
        }

        $data->message->forceFill(['message' => $data->body])->save();

        Event::dispatch(new MessageEdited($data->message));

        return $data->message;
    }
}
