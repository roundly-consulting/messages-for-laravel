<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\Actions\EditMessage;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * One message — `Messages::message($message)`, or `Messages::thread($t)->message($m)` to
 * also check the message belongs to that thread.
 */
final readonly class MessageHandle
{
    public function __construct(
        private MessagesManager $manager,
        private Message $message,
    ) {}

    /** The message this handle is scoped to. */
    public function model(): Message
    {
        return $this->message;
    }

    /** Reword the message. `$by` must be its author. */
    public function edit(string $body, ?Model $by = null): Message
    {
        return $this->manager->perform(
            new MessagingCall(MessagingOperation::Edit, thread: $this->thread(), message: $this->message, actor: $by, text: $body),
            EditMessage::class,
            fn (EditMessage $action): Message => $action->execute(new EditMessageData($this->message, $body, $by)),
        );
    }

    /**
     * Unsend (soft-delete) the message. `$by` must be its author or, in a group thread with
     * roles enforced, an owner or admin.
     */
    public function delete(?Model $by = null): Message
    {
        return $this->manager->perform(
            new MessagingCall(MessagingOperation::Delete, thread: $this->thread(), message: $this->message, actor: $by),
            DeleteMessage::class,
            fn (DeleteMessage $action): Message => $action->execute($this->message, $by),
        );
    }

    private function thread(): ?Thread
    {
        $thread = $this->message->thread;

        return $thread instanceof Thread ? $thread : null;
    }
}
