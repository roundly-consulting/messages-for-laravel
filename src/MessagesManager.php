<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Exceptions\ThreadContextException;
use RoundlyConsulting\Messages\Handles\ChannelsHandle;
use RoundlyConsulting\Messages\Handles\MessageHandle;
use RoundlyConsulting\Messages\Handles\ThreadHandle;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Support\ThreadContext;
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
     *
     * @throws ThreadContextException for a thread outside the current context
     */
    public function to(Thread $thread): PendingMessage
    {
        $this->threadContext()->guard($thread);

        return new PendingMessage($this, $thread);
    }

    /**
     * Find the direct (1:1) thread between two models, creating it when they have none. With
     * a context, the pair has one direct thread per context: the one in that context is found
     * or created, never one of another context.
     *
     * @throws ThreadContextException for an unsaved context, or one other than the current one
     */
    public function direct(Model $first, Model $second, ?Model $context = null): Thread
    {
        return $this->perform(
            new MessagingCall(MessagingOperation::Direct, participant: $second, actor: $first, context: $context),
            FindOrCreateDirectThread::class,
            static fn (FindOrCreateDirectThread $action): Thread => $action->execute($first, $second, $context),
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
     * Unread messages for the participant, in one thread or across all of theirs — or across
     * all of theirs in one context.
     *
     * @throws ThreadContextException for a thread outside the current context
     */
    public function unreadCount(Model $participant, ?Thread $thread = null, ?Model $context = null): int
    {
        if ($thread !== null) {
            $this->threadContext()->guard($thread);

            return $thread->unreadCountFor($participant);
        }

        return MessageModel::class()::query()
            ->unreadFor($participant)
            ->when($context, static fn (Builder $query, Model $context): Builder => $query->whereHas(
                'thread',
                static fn (Builder $thread): Builder => $thread->forContext($context),
            ))
            ->count();
    }

    /**
     * The participant's threads as an optimised inbox: newest activity first, with the
     * latest message, participants, and per-thread unread counts eager loaded.
     *
     * A null `$page` reads the current page from the request (`?page=`, or `?{$pageName}=`),
     * exactly like Eloquent's own `paginate()`. A `$context` lists that context's threads only.
     *
     * @return LengthAwarePaginator<int, Thread>
     */
    public function inboxFor(
        Model $participant,
        int $perPage = 15,
        ?int $page = null,
        string $pageName = 'page',
        ?Model $context = null,
    ): LengthAwarePaginator {
        return ThreadModel::class()::query()
            ->inboxFor($participant)
            ->when($context, static fn (Builder $query, Model $context): Builder => $query->forContext($context))
            ->paginate(perPage: $perPage, pageName: $pageName, page: $page);
    }

    /**
     * Threads visible to the model — the ones it takes part in plus every public thread — or
     * only the public threads when no model is given. Newest activity first, latest message
     * and its sender eager loaded. A null `$page` reads the current page from the request. A
     * `$context` lists that context's threads only.
     *
     * @return LengthAwarePaginator<int, Thread>
     */
    public function threads(
        ?Model $for = null,
        int $perPage = 15,
        ?int $page = null,
        string $pageName = 'page',
        ?Model $context = null,
    ): LengthAwarePaginator {
        return ThreadModel::class()::query()
            ->visibleTo($for)
            ->when($context, static fn (Builder $query, Model $context): Builder => $query->forContext($context))
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
     *
     * @throws ThreadContextException for a thread outside the current context
     */
    public function thread(Thread $thread): ThreadHandle
    {
        $this->threadContext()->guard($thread);

        return new ThreadHandle($this, $thread);
    }

    /**
     * Edit or delete one message.
     *
     * @throws ThreadContextException for a message whose thread is outside the current context
     */
    public function message(Message $message): MessageHandle
    {
        $this->threadContext()->guardThreadId($message->thread_id);

        return new MessageHandle($this, $message);
    }

    /**
     * The broadcast channels: their names for a participant or a thread, and the opt-in
     * channel authorisation callbacks.
     */
    public function channels(): ChannelsHandle
    {
        return new ChannelsHandle($this->container);
    }

    /**
     * Permanently delete messages older than the retention window (`messages.prune.days`
     * when omitted), optionally in one thread only. Returns how many were removed.
     *
     * @throws InvalidArgumentException for a window below one day, before anything is deleted
     * @throws ThreadContextException for a thread outside the current context
     */
    public function prune(?int $days = null, ?Thread $thread = null): int
    {
        if ($thread !== null) {
            $this->threadContext()->guard($thread);
        }

        $days ??= MessagesConfig::pruneDays();

        return $this->perform(
            new MessagingCall(MessagingOperation::Prune, thread: $thread, days: $days),
            PruneMessages::class,
            static fn (PruneMessages $action): int => $action->execute(
                new PruneMessagesData(days: $days, threadId: $thread?->getKey()),
            ),
        );
    }

    /**
     * Set the fail-closed context hook: while the closure returns a model, every thread query
     * is limited to exactly that context and the API refuses a thread of another one. Null
     * from the closure means no constraint; passing null removes the closure (the
     * `messages.context.resolver` class, if any, applies again). Call it once, from a service
     * provider — the closure runs on every thread query, so keep it cheap.
     *
     * @param  (Closure(): ?Model)|null  $resolver
     */
    public function resolveContextUsing(?Closure $resolver): void
    {
        $this->threadContext()->resolveUsing($resolver);
    }

    /**
     * The context the fail-closed hook limits thread queries to right now, or null.
     *
     * @throws ThreadContextException when the resolver returns a non-model or an unsaved one
     */
    public function currentContext(): ?Model
    {
        return $this->threadContext()->current();
    }

    /**
     * Run the callback with the fail-closed context hook switched off — an admin screen or a
     * job that must reach every context.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function withoutContextScope(Closure $callback): mixed
    {
        return $this->threadContext()->suspended($callback);
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

    private function threadContext(): ThreadContext
    {
        return $this->container->make(ThreadContext::class);
    }
}
