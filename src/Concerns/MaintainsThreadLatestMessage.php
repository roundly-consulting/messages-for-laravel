<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Concerns;

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\ThreadModel;

/**
 * Keeps `messaging_threads.last_message_id` telling the truth.
 *
 * {@see Thread::latestMessage()} is a stored pointer rather than a live query, because
 * resolving it live cannot express "one row per thread" and made the inbox hydrate every
 * message of every thread on the page (25,000 rows for 25, at 297ms — see migration 0010).
 *
 * The price of that is this file. A live query needs no maintenance and is right by
 * construction; a pointer is right only where something updates it. So the rule here is
 * deliberately blunt: **recompute on every event that can change which message is newest**,
 * and recompute by running the same ordered lookup the relation used to run, rather than
 * reasoning about whether the changed message "should" win.
 *
 * That is a real cost — one indexed query per write — and it is the right trade only because
 * the ratio is so lopsided: an inbox is read constantly and written to occasionally, the
 * lookup is a single index seek (0011 adds the index it needs), and the alternative was
 * hundreds of milliseconds and tens of thousands of hydrated models on every read.
 *
 * Cheaper shortcuts were rejected for being wrong rather than for being fast:
 *  - "a new message is always the newest" — false. A host backfilling imported history
 *    inserts messages with old timestamps and new keys.
 *  - "only recompute when the deleted message WAS the pointer" — true but fragile: it makes
 *    correctness depend on the pointer already being right, so one missed update stays missed.
 *
 * Bulk operations bypass model events entirely and therefore bypass this trait;
 * {@see RoundlyConsulting\Messages\Actions\PruneMessages} recomputes explicitly for that
 * reason.
 *
 * @mixin Message
 */
trait MaintainsThreadLatestMessage
{
    public static function bootMaintainsThreadLatestMessage(): void
    {
        // `created` covers sends and backfills alike. `updated` covers a moved `created_at`
        // (an import correcting its history) and a `thread_id` reassignment. `deleted` fires
        // for a soft delete (unsend) and a hard delete; `restored` for an undo; `forceDeleted`
        // for a prune of a single model. Each one can change the answer, so each one asks
        // again.
        foreach (['created', 'updated', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, static function (Message $message): void {
                $message->syncThreadLatestMessage();
            });
        }
    }

    /**
     * Recompute the owning thread's pointer.
     *
     * `getOriginal('thread_id')` matters: an update that moves a message between threads
     * leaves the OLD thread stale otherwise. Both are refreshed.
     */
    public function syncThreadLatestMessage(): void
    {
        $threadIds = array_unique(array_filter([
            $this->thread_id,
            $this->getOriginal('thread_id'),
        ], static fn (mixed $id): bool => $id !== null), SORT_REGULAR);

        foreach ($threadIds as $threadId) {
            $latest = self::syncLatestMessageFor($threadId);

            // If this message already holds its thread in memory, keep that instance honest
            // too — otherwise the caller's own object would answer from a pointer that was
            // true when it was loaded and is not any more.
            if ($this->relationLoaded('thread') && $this->thread instanceof Thread
                && $this->thread->getKey() === $threadId) {
                self::applyLatestMessageTo($this->thread, $latest);
            }
        }
    }

    /**
     * Point one thread at its newest surviving message, or at nothing if it has none.
     *
     * Returns the id it settled on, so callers holding the thread can update it without a
     * second lookup.
     */
    public static function syncLatestMessageFor(int|string $threadId): int|string|null
    {
        $message = MessageModel::class();
        $thread = ThreadModel::class();

        /** @var int|string|null $latest */
        $latest = $message::query()
            ->where('thread_id', $threadId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        // `toBase()` on purpose: this is bookkeeping, not a change to the thread. An Eloquent
        // update would stamp `updated_at` — making every send, unsend and restore look like an
        // edit of the thread itself — and would fire thread model events, which broadcast.
        // `withTrashed()` because a soft-deleted thread's pointer must stay correct for when
        // it is restored.
        $thread::query()
            ->withTrashed()
            ->whereKey($threadId)
            ->toBase()
            ->update(['last_message_id' => $latest]);

        return $latest;
    }

    /**
     * Write a freshly-computed pointer onto a Thread instance the caller is still holding.
     *
     * `latestMessage` is a `belongsTo`, so it reads the pointer from the parent's own
     * attribute. That is what makes it fast — the eager load asks for 25 known ids instead of
     * ranking a table — but it also means a Thread object loaded before a message was sent
     * would keep answering from the value it was loaded with. Anywhere this package writes a
     * message on a thread the caller handed us, we hand the caller back a correct thread.
     *
     * `syncOriginalAttribute` keeps the model out of a dirty state: this is us catching the
     * instance up with the database, not an unsaved edit, and leaving it dirty would make the
     * next `save()` write the column back redundantly.
     */
    public static function applyLatestMessageTo(Thread $thread, int|string|null $latestId): void
    {
        $thread->setAttribute('last_message_id', $latestId);
        $thread->syncOriginalAttribute('last_message_id');
        $thread->unsetRelation('latestMessage');
    }
}
