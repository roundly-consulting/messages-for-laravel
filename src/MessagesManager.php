<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Handles\MessageHandle;
use RoundlyConsulting\Messages\Handles\ThreadHandle;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\ThreadModel;

/**
 * The messaging API: the root behind the {@see Facades\Messages} facade, and the class to
 * inject when you prefer dependency injection.
 *
 * Not final on purpose: {@see Testing\MessagesFake} extends it, so a constructor-injected
 * manager receives the fake under `Messages::fake()`.
 */
class MessagesManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * Begin a new conversation: add participants, pick its visibility, then `create()` it.
     */
    public function start(?string $name = null): PendingThread
    {
        return new PendingThread($this, $name);
    }

    /**
     * Begin a message to the thread: pick a sender, a reply target or attachments, then
     * `send()` it.
     */
    public function to(Thread $thread): PendingMessage
    {
        return new PendingMessage($this, $thread);
    }

    /**
     * Find the direct (1:1) thread between two models, creating it when they have none.
     */
    public function direct(Model $first, Model $second): Thread
    {
        return $this->perform(
            new MessagingCall(MessagingOperation::Direct, participant: $second, actor: $first),
            FindOrCreateDirectThread::class,
            static fn (FindOrCreateDirectThread $action): Thread => $action->execute($first, $second),
        );
    }

    /**
     * Send a plain text message. A null sender sends it without one.
     */
    public function send(Thread $thread, ?Model $sender, string $body): Message
    {
        $pending = $this->to($thread);

        if ($sender !== null) {
            $pending->from($sender);
        }

        return $pending->send($body);
    }

    /**
     * Move the participant's read pointer to the thread's newest message.
     */
    public function markRead(Thread $thread, Model $participant): Participant
    {
        return $this->thread($thread)->markRead($participant);
    }

    /**
     * Unread messages for the participant, in one thread or across all of theirs.
     */
    public function unreadCount(Model $participant, ?Thread $thread = null): int
    {
        if ($thread !== null) {
            return $thread->unreadCountFor($participant);
        }

        return MessageModel::class()::query()->unreadFor($participant)->count();
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
        return ThreadModel::class()::query()
            ->inboxFor($participant)
            ->paginate(perPage: $perPage, pageName: $pageName, page: $page);
    }

    /**
     * Threads visible to the model — the ones it takes part in plus every public thread — or
     * only the public threads when no model is given. Newest activity first, latest message
     * and its sender eager loaded.
     *
     * @return LengthAwarePaginator<int, Thread>
     */
    public function threads(
        ?Model $for = null,
        int $perPage = 15,
        int $page = 1,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        return ThreadModel::class()::query()
            ->visibleTo($for)
            ->with('latestMessage.sender')
            ->latest('last_activity_at')
            // `last_activity_at` ties constantly (second precision, stamped `now()`), and an
            // unstable sort under LIMIT/OFFSET can show one thread on two pages and another on
            // none. Every key type is time-ordered (sequence, uuid7, ulid), so `id` desc is a
            // deterministic tiebreak that agrees with the newest-first intent above.
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, pageName: $pageName, page: $page);
    }

    /**
     * Everything that happens inside one thread: rename, archive, read state, typing, its
     * messages and its participants.
     */
    public function thread(Thread $thread): ThreadHandle
    {
        return new ThreadHandle($this, $thread);
    }

    /**
     * Edit or delete one message.
     */
    public function message(Message $message): MessageHandle
    {
        return new MessageHandle($this, $message);
    }

    /**
     * Permanently delete messages older than the retention window (`messages.prune.days`
     * when omitted), optionally in one thread only. Returns how many were removed.
     */
    public function prune(?int $days = null, ?Thread $thread = null): int
    {
        $days ??= (int) config('messages.prune.days', 90);

        return $this->perform(
            new MessagingCall(MessagingOperation::Prune, thread: $thread, days: $days),
            PruneMessages::class,
            static fn (PruneMessages $action): int => $action->execute(
                new PruneMessagesData(days: $days, threadId: $thread?->getKey()),
            ),
        );
    }

    /**
     * Run one state-changing operation. Every builder, handle and model trait funnels its
     * terminal call through here, resolving the action from the container, so host
     * overrides apply and the fake records the call.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @return TResult
     */
    public function perform(MessagingCall $call, string $action, Closure $execute): mixed
    {
        return $execute($this->container->make($action));
    }
}
