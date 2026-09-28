<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Concerns;

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
     * Threads this model participates in, most-recent activity first.
     *
     * @return Collection<int, Thread>
     */
    public function threads(): Collection
    {
        $model = ThreadModel::class();

        return $model::query()->forParticipant($this)->get();
    }

    /**
     * Threads this model participates in, most-recent activity first. Alias of
     * {@see threads()} with a chat-inbox name.
     *
     * @return Collection<int, Thread>
     */
    public function conversations(): Collection
    {
        return $this->threads();
    }

    /**
     * Start a thread and add this model plus the others as participants.
     *
     * @param  Model|iterable<int, Model>  $participants
     */
    public function startConversationWith(Model|iterable $participants, ?string $name = null): Thread
    {
        $others = $participants instanceof Model
            ? [$participants]
            : [...$participants];

        return app(MessagesManager::class)->start($name)->withParticipants([$this, ...$others])->create();
    }

    /** Find or create the 1:1 direct thread between this model and the other. */
    public function conversationWith(Model $other): Thread
    {
        return app(MessagesManager::class)->direct($this, $other);
    }

    public function sendMessageTo(Thread $thread, string $body): Message
    {
        return app(MessagesManager::class)->to($thread)->from($this)->send($body);
    }

    /** Ensure this model is a participant of the given thread. */
    public function joinThread(Thread $thread): Participant
    {
        $existing = $thread->participants()->whereMorphedTo('participant', $this)->first();

        if ($existing instanceof Participant) {
            return $existing;
        }

        return app(MessagesManager::class)->thread($thread)->participants()->add($this);
    }

    /**
     * Threads with at least one message this model has not read.
     *
     * @return Collection<int, Thread>
     */
    public function unreadThreads(): Collection
    {
        return $this->threads()->filter(
            fn (Thread $thread): bool => $thread->unreadCountFor($this) > 0,
        )->values();
    }

    /** Total unread messages across all threads, or within one thread. */
    public function unreadCount(?Thread $thread = null): int
    {
        return app(MessagesManager::class)->unreadCount($this, $thread);
    }

    public function markThreadRead(Thread $thread): Participant
    {
        return app(MessagesManager::class)->markRead($thread, $this);
    }
}
