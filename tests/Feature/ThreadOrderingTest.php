<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Thread listings order by `last_activity_at` — and that column ties constantly. Laravel
 * stores timestamps at second precision, and a thread's activity is stamped `now()`, so
 * every thread created (or messaged) in the same second shares a value.
 *
 * With no tiebreak the sort is genuinely undefined: the engine may return tied rows in any
 * order, and it is free to return a *different* order for the same query. That is not a
 * cosmetic problem for `paginate()` — an unstable sort under LIMIT/OFFSET can show the same
 * thread on two pages and never show another one at all.
 *
 * SQLite happened to return tied rows in physical insertion order, which is why the suite
 * agreed with itself for the package's whole life. Postgres does not promise that and does
 * not always do it — this surfaced as a 1-in-~24 red on the pgsql leg
 * (`ThreadListingTest > it paginates threads`), reproducible only by re-running.
 *
 * The tiebreak is `id` desc, which is deterministic *and* meaningful rather than arbitrary:
 * {@see HasUuids} mints `Str::uuid7()`, so within one `last_activity_at` the greater id is
 * the later thread — consistent with the "newest first" the primary sort already declares.
 */
$tiedThreads = function (): array {
    Carbon::setTestNow('2026-07-18 12:00:00');

    $threads = collect(range(1, 6))->map(
        fn (int $i): Thread => Thread::factory()->create(['name' => "tied {$i}", 'is_public' => true]),
    );

    // Precondition: the tie is real, not assumed.
    expect($threads->pluck('last_activity_at')->map->format('Y-m-d H:i:s')->unique())->toHaveCount(1);

    return $threads->all();
};

afterEach(fn () => Carbon::setTestNow());

it('paginates tied threads in a stable, newest-first order', function () use ($tiedThreads): void {
    $threads = $tiedThreads();
    $expected = collect($threads)->sortByDesc->getKey()->pluck('name')->values()->all();

    // Repeated identically: an unstable sort is free to answer differently each time, so one
    // agreeing run proves nothing.
    foreach (range(1, 4) as $ignored) {
        expect(Messages::threads()->getCollection()->pluck('name')->all())
            ->toBe($expected);
    }
});

it('orders a participant inbox deterministically when activity ties', function () use ($tiedThreads): void {
    $threads = $tiedThreads();
    $user = User::create();

    foreach ($threads as $thread) {
        Messages::thread($thread)->participants()->add($user);
    }

    $expected = collect($threads)->sortByDesc->getKey()->pluck('name')->values()->all();

    // Both the scope and the repository sort the same list; neither may shuffle.
    expect(Thread::query()->forParticipant($user)->get()->pluck('name')->all())->toBe($expected)
        ->and(Thread::query()->inboxFor($user)->get()->pluck('name')->all())->toBe($expected);
});
