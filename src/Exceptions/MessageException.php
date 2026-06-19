<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Exceptions;

use Exception;
use RoundlyConsulting\Messages\Models\Message;

final class MessageException extends Exception
{
    public static function alreadyDeleted(Message $message): self
    {
        return new self(
            "Message [{$message->getKey()}] has been deleted and can no longer be modified.",
        );
    }
}
