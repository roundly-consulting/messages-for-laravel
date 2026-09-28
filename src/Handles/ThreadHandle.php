<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Handles;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\ArchiveThread;
use RoundlyConsulting\Messages\Actions\MarkRead;
use RoundlyConsulting\Messages\Actions\RenameThread;
use RoundlyConsulting\Messages\Actions\SignalTyping;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * One thread — `Messages::thread($thread)`. A `by:` actor is checked against the thread's
 * roles (see `messages.permissions`); leaving it out skips the check, for trusted callers.
 */
final readonly class ThreadHandle
{
    public function __construct(
        private MessagesManager $manager,
        private Thread $thread,
    ) {}

    /** The thread this handle is scoped to. */
    public function model(): Thread
    {
        return $this->thread;
    }

    public function rename(?string $name, ?Model $by = null): Thread
    {
        return $this->manager->perform(
            new MessagingCall(MessagingOperation::Rename, thread: $this->thread, actor: $by, text: $name),
            RenameThread::class,
            fn (RenameThread $action): Thread => $action->execute($this->thread, $name, $by),
        );
    }

    public function archive(?Model $by = null): Thread
    {
        return $this->manager->perform(
            new MessagingCall(MessagingOperation::Archive, thread: $this->thread, actor: $by),
            ArchiveThread::class,
            fn (ArchiveThread $action): Thread => $action->execute($this->thread, $by),
        );
    }

    /** Move the participant's read pointer to the thread's newest message. */
    public function markRead(Model $participant): Participant
    {
        $participant = $this->participants()->resolve($participant);

        return $this->manager->perform(
            new MessagingCall(MessagingOperation::MarkRead, thread: $this->thread, participant: $participant),
            MarkRead::class,
            fn (MarkRead $action): Participant => $action->execute(new MarkReadData($this->thread, $participant)),
        );
    }

    /** Broadcast a transient "is typing" signal; a no-op while broadcasting is off. */
    public function typing(Model $participant): void
    {
        $participant = $this->participants()->resolve($participant);

        $this->manager->perform(
            new MessagingCall(MessagingOperation::Typing, thread: $this->thread, participant: $participant),
            SignalTyping::class,
            function (SignalTyping $action) use ($participant): void {
                $action->execute($this->thread, $participant);
            },
        );
    }

    /**
     * The thread's messages, newest first, each with its sender eager loaded. A null `$page`
     * reads the current page from the request (`?page=`, or `?{$pageName}=`).
     *
     * @return LengthAwarePaginator<int, Message>
     */
    public function messages(int $perPage = 15, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->thread->messages()
            ->with('sender')
            ->latest()
            // Same-second sends tie on `created_at`; every key type is time-ordered.
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, pageName: $pageName, page: $page);
    }

    /** Add, remove and re-role the thread's participants. */
    public function participants(): ParticipantsHandle
    {
        return new ParticipantsHandle($this->manager, $this->thread);
    }

    /**
     * One message of this thread. A message from any other thread is refused — use this
     * rather than `Messages::message()` whenever the thread comes from the request.
     *
     * @throws MessageException when the message belongs to another thread
     */
    public function message(Message $message): MessageHandle
    {
        if ((string) $message->thread_id !== (string) $this->thread->getKey()) {
            throw MessageException::notInThread($message);
        }

        return new MessageHandle($this->manager, $message);
    }
}
