<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\ParticipantModel;
use RoundlyConsulting\Messages\Support\ThreadModel;

/**
 * Ergonomic messaging helpers for a participant model.
 *
 * The host model must also implement
 * {@see ParticipatesInMessaging}. Every write goes through the {@see MessagesManager}, so
 * `Messages::fake()` records it like a facade call.
 *
 * @mixin Model
 */
trait HasMessaging
{
    /** @return MorphMany<Participant, $this> */
    public function participations(): MorphMany
    {
        return $this->morphMany(ParticipantModel::class(), 'participant');
    }

    /**
     * Threads this model participates in, most-recent activity first — only the given
     * context's when one is given.
     *
     * @return Collection<int, Thread>
     */
    public function threads(?Model $context = null): Collection
    {
        $model = ThreadModel::class();

        return $model::query()
            ->forParticipant($this)
            ->when($context, static fn (Builder $query, Model $context): Builder => $query->forContext($context))
            ->get();
    }

    /**
     * Threads this model participates in, most-recent activity first. Alias of
     * {@see threads()} with a chat-inbox name.
     *
     * @return Collection<int, Thread>
     */
    public function conversations(?Model $context = null): Collection
    {
        return $this->threads($context);
    }

    /**
     * Start a thread and add this model plus the others as participants — in a context when
     * one is given.
     *
     * @param  Model|iterable<int, Model>  $participants
     */
    public function startConversationWith(Model|iterable $participants, ?string $name = null, ?Model $context = null): Thread
    {
        $others = $participants instanceof Model
            ? [$participants]
            : [...$participants];

        $pending = app(MessagesManager::class)->start($name)->withParticipants([$this, ...$others]);

        if ($context !== null) {
            $pending->for($context);
        }

        return $pending->create();
    }

    /** Find or create the 1:1 direct thread between this model and the other (in a context). */
    public function conversationWith(Model $other, ?Model $context = null): Thread
    {
        return app(MessagesManager::class)->direct($this, $other, $context);
    }

    public function sendMessageTo(Thread $thread, string $body): Message
    {
        return app(MessagesManager::class)->to($thread)->from($this)->send($body);
    }

    /**
     * Join the thread yourself. Allowed on a thread open to everyone (`everyone_can_join`); a
     * no-op returning the existing row when this model is already a participant.
     */
    public function joinThread(Thread $thread): Participant
    {
        return app(MessagesManager::class)->thread($thread)->participants()->add($this, by: $this);
    }

    /**
     * Threads with at least one message this model has not read (in a context when given).
     *
     * @return Collection<int, Thread>
     */
    public function unreadThreads(?Model $context = null): Collection
    {
        return $this->threads($context)->filter(
            fn (Thread $thread): bool => $thread->unreadCountFor($this) > 0,
        )->values();
    }

    /** Total unread messages across all threads, within one thread, or within one context. */
    public function unreadCount(?Thread $thread = null, ?Model $context = null): int
    {
        return app(MessagesManager::class)->unreadCount($this, $thread, $context);
    }

    public function markThreadRead(Thread $thread): Participant
    {
        return app(MessagesManager::class)->markRead($thread, $this);
    }
}
