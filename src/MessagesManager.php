<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\MarkRead;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

class MessagesManager
{
    public function __construct(
        private readonly StartThread $startThread,
        private readonly SendMessage $sendMessage,
        private readonly MarkRead $markRead,
        private readonly FindOrCreateDirectThread $findOrCreateDirectThread,
    ) {}

    public function thread(?string $name = null): PendingThread
    {
        return new PendingThread($this->startThread, $name);
    }

    public function to(Thread $thread): PendingMessage
    {
        return new PendingMessage($this->sendMessage, $thread);
    }

    public function direct(Model $first, Model $second): Thread
    {
        return $this->findOrCreateDirectThread->execute($first, $second);
    }

    public function send(Thread $thread, ?Model $sender, string $body): Message
    {
        return $this->sendMessage->execute(new SendMessageData(
            thread: $thread,
            sender: $sender,
            body: $body,
        ));
    }

    public function markRead(Thread $thread, Model $participant): Participant
    {
        return $this->markRead->execute(new MarkReadData($thread, $participant));
    }

    public function unreadCount(Model $participant, ?Thread $thread = null): int
    {
        if ($thread !== null) {
            return $thread->unreadCountFor($participant);
        }

        /** @var class-string<Message> $model */
        $model = config('messages.models.message', Message::class);

        return $model::query()->unreadFor($participant)->count();
    }
}
