<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\Concerns\MaintainsThreadLatestMessage;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Fixtures\QueryRecorder;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The denormalisation's own contract: what `threads.last_message_id` promises, who keeps it
 * true, and where it is allowed to be behind.
 *
 * `latestMessage` used to be a live ordered `hasOne` — it re-queried by thread key, so it
 * could never be stale, but it also could not say "one row per thread" and made an inbox page
 * hydrate 25,000 rows to show 25. It is now a `belongsTo` over a stored pointer: 25 index
 * lookups, 1.9ms instead of 297ms. The price is that a stored answer can rot, and that the
 * relation reads the pointer from the thread instance's own attribute.
 *
 * These pin both halves of that price so neither can drift back:
 *  - the pointer is kept true by {@see MaintainsThreadLatestMessage}
 *    on every write path, including the bulk one that bypasses model events entirely;
 *  - the in-memory boundary is exactly where it is documented to be.
 */
it('keeps the caller thread instance correct across a send', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->create();
    Messages::thread($thread)->participants()->add($user);

    // No refresh: this is the instance the host handed us, and the one they still hold.
    Messages::send($thread, $user, 'first');

    expect($thread->latestMessage?->message)->toBe('first')
        ->and($thread->latestMessagePreview())->toBe('first');

    Messages::send($thread, $user, 'second');

    // ...and again: the relation must not cache the first answer.
    expect($thread->latestMessage?->message)->toBe('second')
        ->and($thread->latestMessagePreview())->toBe('second');
});

/**
 * The documented boundary. A raw model write cannot reach a Thread object it was never given,
 * so that object keeps the pointer it was loaded with — plain `belongsTo` semantics, the same
 * way `$post->author` goes stale if `posts.user_id` changes behind it.
 *
 * Pinned rather than left implicit: it is the one behaviour the live relation had that this
 * one does not, and a reader deserves to find it stated instead of discovering it.
 */
it('leaves a held thread instance behind a raw model write until refreshed', function (): void {
    $thread = Thread::factory()->create();

    Message::factory()->inThread($thread)->create(['message' => 'written raw']);

    // The stored pointer is already correct — the maintenance ran.
    expect(DB::table('messaging_threads')->where('id', $thread->getKey())->value('last_message_id'))
        ->not->toBeNull();

    // ...the object simply has not been told.
    expect($thread->last_message_id)->toBeNull()
        ->and($thread->fresh()?->latestMessage?->message)->toBe('written raw');
});

/**
 * Bulk `forceDelete()` bypasses model events, so nothing in the maintenance trait fires. If
 * PruneMessages did not recompute explicitly, a pruned thread would keep pointing at a row
 * that no longer exists and the inbox would preview a deleted message.
 */
it('recomputes the pointer after a bulk prune', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->create();
    Messages::thread($thread)->participants()->add($user);

    $old = Messages::send($thread, $user, 'ancient');
    $old->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

    $kept = Messages::send($thread, $user, 'recent');

    app(PruneMessages::class)->execute(new PruneMessagesData(days: 90));

    expect($thread->fresh()?->last_message_id)->toBe($kept->getKey())
        ->and($thread->fresh()?->latestMessage?->message)->toBe('recent');
});

/** Pruning a thread's entire history leaves it pointing at nothing, not at a deleted row. */
it('clears the pointer when a prune removes every message', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->create();
    Messages::thread($thread)->participants()->add($user);

    $only = Messages::send($thread, $user, 'ancient');
    $only->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

    app(PruneMessages::class)->execute(new PruneMessagesData(days: 90));

    expect($thread->fresh()?->last_message_id)->toBeNull()
        ->and($thread->fresh()?->latestMessage)->toBeNull()
        ->and($thread->fresh()?->latestMessagePreview())->toBeNull();
});

/**
 * Pointer maintenance is bookkeeping, not an edit of the thread. If it stamped `updated_at`,
 * every send/unsend/restore would look like the thread itself had been modified — breaking
 * any host that syncs or caches on that column.
 */
it('does not touch the thread updated_at when maintaining the pointer', function (): void {
    $thread = Thread::factory()->create();
    $thread->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

    $before = DB::table('messaging_threads')->where('id', $thread->getKey())->value('updated_at');

    $message = Message::factory()->inThread($thread)->create(['message' => 'hi']);
    $message->delete();
    $message->restore();

    expect(DB::table('messaging_threads')->where('id', $thread->getKey())->value('updated_at'))
        ->toBe($before);
});

/**
 * Moving a message between threads must refresh BOTH — the one it left (which may now have a
 * different newest message, or none) and the one it joined. Only recomputing the destination
 * would leave the source pointing at a message that is no longer in it.
 */
it('refreshes both threads when a message moves between them', function (): void {
    $from = Thread::factory()->create();
    $to = Thread::factory()->create();

    $stays = Message::factory()->inThread($from)->create(['message' => 'stays']);
    $stays->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $moves = Message::factory()->inThread($from)->create(['message' => 'moves']);

    expect($from->fresh()?->last_message_id)->toBe($moves->getKey());

    $moves->update(['thread_id' => $to->getKey()]);

    expect($from->fresh()?->latestMessage?->message)->toBe('stays')
        ->and($to->fresh()?->latestMessage?->message)->toBe('moves');
});

/**
 * Two sends racing on one thread: each looks up the newest message, then writes it. Without a
 * lock, T1 can compute its own message (T2's is not committed yet), wait behind T2's write, and
 * then overwrite T2's newer answer with its older one — the pointer ends on m1 though m2 is
 * newest, and an unsend racing a send ends the same way. One connection cannot block on its own
 * lock, so this pins the guard instead of replaying the race: the thread row is locked, in a
 * transaction that is still open, before the newest-message lookup runs.
 */
it('locks the thread row before it looks up the newest message', function (string $flow): void {
    $user = User::create();
    $thread = Messages::start('Hello')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'first');

    if ($flow === 'restore') {
        $message->delete();
    }

    $log = QueryRecorder::during(match ($flow) {
        'send' => fn () => Messages::send($thread, $user, 'second'),
        'unsend' => fn () => $message->delete(),
        'restore' => fn () => $message->restore(),
    });

    $lookup = QueryRecorder::first($log, static fn (string $sql): bool => str_starts_with($sql, 'select')
        && str_contains($sql, 'from messaging_messages ')
        && str_contains($sql, 'order by created_at desc'));

    expect($lookup)->not->toBeNull()
        ->and(QueryRecorder::lockHeldAt($log, (int) $lookup, 'messaging_threads'))->toBeTrue();
})->with(['send', 'unsend', 'restore']);

/**
 * On MySQL (REPEATABLE READ) a plain read inside a transaction answers from a snapshot that may
 * predate the thread lock — taken by an earlier read in the same transaction, a host's own
 * transaction included — so the lookup must be a locking read, which always sees the latest
 * committed rows. Postgres reads a fresh snapshot per statement and needs no lock there.
 *
 * The suite has no MySQL engine, so this runs the lookup on the in-memory connection with its
 * driver reported as mysql — the only thing the decision reads.
 */
it('makes the newest-message lookup a locking read on mysql', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->withParticipant($user)->create();
    Messages::send($thread, $user, 'first');

    $log = QueryRecorder::during(function () use ($thread): void {
        $connection = DB::connection();
        $config = new ReflectionProperty($connection, 'config');
        $original = $config->getValue($connection);
        $config->setValue($connection, ['driver' => 'mysql'] + $original);

        try {
            Message::syncLatestMessageFor($thread->getKey());
        } finally {
            $config->setValue($connection, $original);
        }
    });

    $lookup = QueryRecorder::first($log, static fn (string $sql): bool => str_contains($sql, 'from messaging_messages ')
        && str_contains($sql, 'order by created_at desc'));

    expect($lookup)->not->toBeNull()
        ->and(QueryRecorder::locksShared($log[(int) $lookup]['sql']))->toBeTrue()
        ->and(QueryRecorder::lockHeldAt($log, (int) $lookup, 'messaging_threads'))->toBeTrue();
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'reports the in-memory connection as mysql');

/** ...and nowhere else: a Postgres row lock is a write to the row, for no gain at READ COMMITTED. */
it('keeps the newest-message lookup a plain read off mysql', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->withParticipant($user)->create();
    Messages::send($thread, $user, 'first');

    $log = QueryRecorder::during(fn () => Message::syncLatestMessageFor($thread->getKey()));

    $lookup = QueryRecorder::first($log, static fn (string $sql): bool => str_contains($sql, 'from messaging_messages ')
        && str_contains($sql, 'order by created_at desc'));

    expect($lookup)->not->toBeNull()
        ->and(QueryRecorder::locksShared($log[(int) $lookup]['sql']))->toBeFalse();
})->skip(fn (): bool => in_array(DriverMatrix::driver(), ['mysql', 'mariadb'], true), 'mysql locks the lookup on purpose');

/**
 * "A just-sent message is the newest" is false for a thread holding a message stamped later
 * than now — imported history, a skewed clock. The sync computes the real newest; the caller's
 * thread must be told that answer, not the id of the message just sent.
 */
it('leaves the caller thread at the pointer the sync computed', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->withParticipant($user)->create();

    $later = Message::factory()->inThread($thread)->create(['message' => 'stamped later']);
    $later->forceFill(['created_at' => now()->addHour()])->saveQuietly();
    Message::syncLatestMessageFor($thread->getKey());

    Messages::send($thread, $user, 'sent now');

    $stored = DB::table('messaging_threads')->where('id', $thread->getKey())->value('last_message_id');

    expect($stored)->toEqual($later->getKey())
        ->and($thread->last_message_id)->toEqual($stored)
        ->and($thread->latestMessage?->message)->toBe('stamped later');
});
