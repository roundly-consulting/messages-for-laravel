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

    public static function replyAcrossThreads(): self
    {
        $message = trans('messages::messages.reply.cross-thread');

        return new self(is_string($message) ? $message : 'A reply must target a message in the same thread.');
    }

    public static function notInThread(Message $message): self
    {
        $text = trans('messages::messages.scope.message-in-another-thread', [
            'message' => (string) $message->getKey(),
        ]);

        return new self(is_string($text) ? $text : 'The message belongs to another thread.');
    }

    public static function attachmentIsNotAnImage(): self
    {
        return new self('A preview URL can only be generated for an image attachment.');
    }
}
