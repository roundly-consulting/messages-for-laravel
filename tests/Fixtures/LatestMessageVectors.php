<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

use function expect;

/**
 * The frozen answers for "what is a thread's latest message".
 *
 * These vectors exist to be written down BEFORE the relation's implementation changes, and to
 * be re-run unchanged afterwards. The relation is currently *correct* — an ordered `hasOne`
 * that resolves the winner live — and the only reason to touch it is speed. So the risk is
 * not "does it throw", it is "does a faster shape quietly answer differently". A pin written
 * after the fact cannot detect that; one written before it can.
 *
 * The vectors are deliberately the cases a *denormalised* answer gets wrong, because that is
 * the shape being adopted: a stored pointer is only as correct as the code that maintains it,
 * and every scenario below is a moment where a live query needs no maintenance and a pointer
 * does — a backfill that lands out of order, an unsend, a restore, a hard delete that must
 * fall back to the previous message, and a thread emptied down to nothing.
 *
 * They are key-type agnostic on purpose: `messages.primary_key_type` is configurable
 * (bigint/uuid/ulid) and the answers must not depend on which is set, so every leg runs this
 * same table.
 */
final class LatestMessageVectors
{
    /**
     * Every scenario, as name => [builder, expected latest message body (or null)].
     *
     * @return array<string, callable(): array{0: Thread, 1: string|null}>
     */
    public static function scenarios(): array
    {
        return [
            'a thread with no messages has no latest' => function (): array {
                return [self::thread(), null];
            },

            'a single message is the latest' => function (): array {
                $thread = self::thread();
                self::send($thread, 'only');

                return [$thread, 'only'];
            },

            'the newest timestamp wins' => function (): array {
                $thread = self::thread();
                self::send($thread, 'older', at: now()->subDay());
                self::send($thread, 'newer', at: now());

                return [$thread, 'newer'];
            },

            // Ties are the normal case, not the edge: second-precision timestamps under a
            // frozen clock give every message one instant, so the id tiebreak decides.
            'tied timestamps fall back to the newest key' => function (): array {
                Carbon::setTestNow('2026-07-18 12:00:00');
                $thread = self::thread();
                self::send($thread, 'tied 1');
                self::send($thread, 'tied 2');
                self::send($thread, 'tied 3');

                return [$thread, 'tied 3'];
            },

            // A host importing history inserts an OLD message with a NEW key. Sorting by key
            // alone would call a 2019 import the latest. The import fires model events (it is
            // a normal write), so the pointer must land on the older timestamp.
            'a backfilled message does not win on its newer key' => function (): array {
                $thread = self::thread();
                self::send($thread, 'sent today');
                self::send($thread, 'imported history', at: now()->subYears(7));

                return [$thread, 'sent today'];
            },

            // Unsend: the previous message must resurface.
            'unsending the latest falls back to the previous' => function (): array {
                $thread = self::thread();
                self::send($thread, 'first', at: now()->subHour());
                $last = self::send($thread, 'second', at: now());
                $last->delete();

                return [$thread, 'first'];
            },

            'restoring an unsent message makes it latest again' => function (): array {
                $thread = self::thread();
                self::send($thread, 'first', at: now()->subHour());
                $last = self::send($thread, 'second', at: now());
                $last->delete();
                $last->restore();

                return [$thread, 'second'];
            },

            'unsending every message leaves no latest' => function (): array {
                $thread = self::thread();
                $only = self::send($thread, 'only');
                $only->delete();

                return [$thread, null];
            },

            'force-deleting the latest falls back to the previous' => function (): array {
                $thread = self::thread();
                self::send($thread, 'first', at: now()->subHour());
                $last = self::send($thread, 'second', at: now());
                $last->forceDelete();

                return [$thread, 'first'];
            },

            'force-deleting every message leaves no latest' => function (): array {
                $thread = self::thread();
                $only = self::send($thread, 'only');
                $only->forceDelete();

                return [$thread, null];
            },

            // An edit that moves a message's timestamp backwards hands the crown over. A
            // normal `update()` fires the `updated` event, so the pointer follows.
            'editing a timestamp backwards re-elects the other message' => function (): array {
                $thread = self::thread();
                self::send($thread, 'first', at: now()->subHour());
                $second = self::send($thread, 'second', at: now());
                $second->update(['created_at' => now()->subDays(3)]);

                return [$thread, 'first'];
            },
        ];
    }

    /**
     * Assert one scenario through every path a host can read the latest message by. They must
     * all agree — a pointer that updates the eager load but not the lazy read (or vice versa)
     * is exactly the bug this catches.
     *
     * Each thread here is re-read from the database first, and that is a deliberate, measured
     * change rather than a convenience. `latestMessage` is now a `belongsTo` over a stored
     * pointer, so it answers from the thread row's own column — which is exactly why the inbox
     * got 156x faster (it asks for 25 known ids instead of ranking a table), and exactly why a
     * Thread *object* loaded before a message was written keeps answering from the value it
     * was loaded with. That is ordinary Eloquent `belongsTo` semantics — `$post->author` is
     * stale in the same way if someone repoints `posts.user_id` behind it — but it IS a change
     * from the live ordered `hasOne`, which re-queried by thread key and so could never be
     * behind. LatestMessagePointerTest pins that boundary explicitly:
     * the package's own send API keeps the caller's instance correct, and a raw model write
     * requires a refresh.
     */
    public static function assertScenario(Thread $thread, ?string $expected): void
    {
        $user = User::create();
        Messages::thread($thread)->participants()->add($user);

        // 1. Lazy relation read.
        $reloaded = $thread->fresh();
        expect($reloaded?->latestMessage()->first()?->message)->toBe($expected);

        // 2. Eager load on a fresh model.
        $fresh = Thread::query()->whereKey($thread->getKey())->with('latestMessage')->first();
        expect($fresh?->latestMessage?->message)->toBe($expected);

        // 3. The inbox hot path — the query this whole change exists to speed up.
        $inbox = Thread::query()->inboxFor($user)->get()->firstWhere('id', $thread->getKey());
        expect($inbox?->latestMessage?->message)->toBe($expected);

        // 4. The preview helper, both cold and with the relation already loaded.
        expect($thread->fresh()?->latestMessagePreview())->toBe($expected)
            ->and($fresh?->latestMessagePreview())->toBe($expected);

        // 5. The stored pointer itself agrees with the answer — the denormalised column is the
        //    thing that can rot, so it is asserted directly rather than only through a relation
        //    that might be reading it correctly from a wrong value.
        expect($reloaded?->last_message_id)->toBe(
            $expected === null ? null : Message::query()
                ->where('thread_id', $thread->getKey())
                ->where('message', $expected)
                ->value('id'),
        );
    }

    private static function thread(): Thread
    {
        return Thread::factory()->create();
    }

    private static function send(Thread $thread, string $body, ?Carbon $at = null): Message
    {
        $message = Message::factory()->inThread($thread)->create(['message' => $body]);

        if ($at !== null) {
            // A normal `update()`, not `saveQuietly()`: correcting a message's timestamp (an
            // import fixing its history) fires the `updated` event, which is what keeps the
            // stored pointer honest. `saveQuietly` deliberately bypasses every event — the
            // pointer cannot see it, exactly as it cannot see a raw DB write, and that
            // boundary is pinned in LatestMessagePointerTest rather than smuggled in here.
            $message->update(['created_at' => $at]);
        }

        return $message->refresh();
    }
}
