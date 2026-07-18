<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * `Thread::latestMessage()` — the relation that was broken on every real engine.
 *
 * It used `latestOfMany()`, which resolves its winner with `MAX(<key>)`. The key here is a
 * uuid and Postgres ships no `max(uuid)` aggregate, so *every* read of this relation threw
 * `SQLSTATE[42883] function max(uuid) does not exist` — the inbox eager-load,
 * `latestMessagePreview()`, `MarkRead`, and `Participant::unreadCount()`. 23 tests died on
 * the pgsql leg the moment one existed. SQLite aggregates uuids as text without complaint,
 * which is the only reason 236 green tests never saw it.
 *
 * These cases pin the two halves of the fix independently, so neither can rot:
 *  - the relation resolves at all (bites only on an engine with real uuid types);
 *  - it picks a deterministic winner when `created_at` ties (bites on every engine).
 */
it('resolves the latest message without aggregating over the uuid key', function (): void {
    $thread = Thread::factory()->create();

    Message::factory()->inThread($thread)->create(['message' => 'first']);
    $newest = Message::factory()->inThread($thread)->create(['message' => 'second']);

    // Reading the relation is the whole assertion on Postgres: `latestOfMany()` never got
    // this far, it raised max(uuid) while building the join subquery.
    expect($thread->latestMessage()->first()?->getKey())->toBe($newest->getKey())
        ->and($thread->fresh()?->load('latestMessage')->latestMessage?->message)->toBe('second')
        ->and($thread->latestMessagePreview())->toBe('second');
});

/**
 * The tiebreak, and why it is not cosmetic: Laravel stores timestamps at second precision,
 * so messages sent in the same second share a `created_at`. Under a frozen clock — which is
 * this suite's normal state — *every* message in a thread ties.
 *
 * `id` desc resolves it correctly rather than arbitrarily because `HasUuids` mints
 * `Str::uuid7()`, which is time-ordered: within one `created_at`, the greater uuid is the
 * later message. Ordering on `created_at` alone would leave the winner to whatever the
 * engine happened to return first.
 */
it('picks the last message deterministically when created_at ties', function (): void {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $thread = Thread::factory()->create();

    $messages = collect(range(1, 5))->map(fn (int $i): Message => Message::factory()
        ->inThread($thread)
        ->create(['message' => "tied {$i}"]));

    // Precondition: the tie is real, not assumed — all five share one created_at.
    expect($messages->pluck('created_at')->map->format('Y-m-d H:i:s')->unique())->toHaveCount(1);

    // uuid7 is monotonic, so the last-written row is the greatest key: the winner is the
    // last message sent, not an arbitrary member of the tied set.
    expect($thread->latestMessage()->first()?->getKey())->toBe($messages->last()->getKey())
        ->and($thread->latestMessagePreview())->toBe('tied 5');

    Carbon::setTestNow();
});

/**
 * The primary sort is `created_at`, not the key. A host that backfills history inserts old
 * messages *after* new ones, so their uuid7 keys are greater while their timestamps are
 * older. Sorting by key alone — which is what the old `MAX(id)` did — would call an imported
 * 2019 message the "latest".
 */
it('prefers the newest timestamp over the newest key', function (): void {
    $thread = Thread::factory()->create();

    $current = Message::factory()->inThread($thread)->create(['message' => 'sent today']);

    // Backfilled afterwards: greater uuid7 key, older timestamp.
    $backfilled = Message::factory()->inThread($thread)->create(['message' => 'imported history']);
    $backfilled->forceFill(['created_at' => now()->subYears(7)])->saveQuietly();

    expect($backfilled->getKey())->toBeGreaterThan($current->getKey())
        ->and($thread->latestMessage()->first()?->getKey())->toBe($current->getKey());
});
