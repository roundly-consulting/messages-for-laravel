<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * The two named regressions behind `Thread::latestMessage()`, kept as focused pins. The full
 * behavioural table — every read path, every mutation, on bigint AND uuid AND ulid — lives in
 * LatestMessageVectors; this file guards the two specific bugs the relation was rebuilt around
 * so they stay named and findable.
 *
 * 1. `latestOfMany()` resolved its winner with `MAX(<key>)`, and `CanBeOneOfMany::ofMany()`
 *    forces the primary key in as a tiebreak column. On the `uuid` key setting Postgres has no
 *    `max(uuid)`, so every read threw `function max(uuid) does not exist`. That leg is proven
 *    on real uuid keys in UuidKeyTest; the relation is now a plain `belongsTo` and aggregates
 *    nothing.
 * 2. The winner is the newest by `created_at`, not by key — a backfilled import has a newer
 *    key but an older timestamp and must NOT win.
 *
 * The relation is now a `belongsTo` over the stored `threads.last_message_id` pointer, so it
 * reads from the thread row's own column. These pins therefore read from a persisted thread
 * (Thread::fresh()) — the pointer boundary itself is pinned in LatestMessagePointerTest.
 */
afterEach(fn () => Carbon::setTestNow());

it('resolves the latest message without an aggregate', function (): void {
    $thread = Thread::factory()->create();

    Message::factory()->inThread($thread)->create(['message' => 'first']);
    $newest = Message::factory()->inThread($thread)->create(['message' => 'second']);

    $fresh = $thread->fresh();

    expect($fresh?->latestMessage()->first()?->getKey())->toBe($newest->getKey())
        ->and($fresh?->load('latestMessage')->latestMessage?->message)->toBe('second')
        ->and($fresh?->latestMessagePreview())->toBe('second');
});

/**
 * Ties are the normal case, not the edge: second-precision timestamps under a frozen clock
 * give every message one `created_at`, so the `id` desc tiebreak is the only thing deciding
 * the winner. It is monotonic on every supported key type (sequence / uuid7 / ulid).
 */
it('picks the last message deterministically when created_at ties', function (): void {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $thread = Thread::factory()->create();

    $messages = collect(range(1, 5))->map(fn (int $i): Message => Message::factory()
        ->inThread($thread)
        ->create(['message' => "tied {$i}"]));

    expect($messages->pluck('created_at')->map->format('Y-m-d H:i:s')->unique())->toHaveCount(1);

    $fresh = $thread->fresh();

    expect($fresh?->latestMessage()->first()?->getKey())->toBe($messages->last()->getKey())
        ->and($fresh?->latestMessagePreview())->toBe('tied 5');
});

/**
 * The primary sort is `created_at`, not the key. A host that backfills history inserts old
 * messages after new ones, so their keys are greater while their timestamps are older.
 * Sorting by key alone — the old `MAX(id)` — would call an imported message the latest. The
 * import fires model events, so the pointer follows the timestamp.
 */
it('prefers the newest timestamp over the newest key', function (): void {
    $thread = Thread::factory()->create();

    $current = Message::factory()->inThread($thread)->create(['message' => 'sent today']);

    $backfilled = Message::factory()->inThread($thread)->create(['message' => 'imported history']);
    $backfilled->update(['created_at' => now()->subYears(7)]);

    expect($backfilled->getKey())->toBeGreaterThan($current->getKey())
        ->and($thread->fresh()?->latestMessage()->first()?->getKey())->toBe($current->getKey());
});
