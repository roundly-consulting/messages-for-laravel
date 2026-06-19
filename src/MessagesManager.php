<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /** Find or create the direct (1:1) thread between two participants. */
    public function between(Model $first, Model $second): Thread
    {
        return $this->findOrCreateDirectThread->execute($first, $second);
    }

    /**
     * The participant's threads as an optimised inbox: newest activity first, with the
     * latest message, participants, and per-thread unread counts eager loaded.
     *
     * @return LengthAwarePaginator<int, Thread>
     */
    public function inboxFor(
        Model $participant,
        int $perPage = 15,
        int $page = 1,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        /** @var class-string<Thread> $model */
        $model = config('messages.models.thread', Thread::class);

        return $model::query()
            ->inboxFor($participant)
            ->paginate(perPage: $perPage, pageName: $pageName, page: $page);
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
