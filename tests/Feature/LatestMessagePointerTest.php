<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\Concerns\MaintainsThreadLatestMessage;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

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
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    // No refresh: this is the instance the host handed us, and the one they still hold.
    messaging()->messages()->sendMessage($thread, $user, 'first');

    expect($thread->latestMessage?->message)->toBe('first')
        ->and($thread->latestMessagePreview())->toBe('first');

    messaging()->messages()->sendMessage($thread, $user, 'second');

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
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    $old = messaging()->messages()->sendMessage($thread, $user, 'ancient');
    $old->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

    $kept = messaging()->messages()->sendMessage($thread, $user, 'recent');

    app(PruneMessages::class)->execute(new PruneMessagesData(days: 90));

    expect($thread->fresh()?->last_message_id)->toBe($kept->getKey())
        ->and($thread->fresh()?->latestMessage?->message)->toBe('recent');
});

/** Pruning a thread's entire history leaves it pointing at nothing, not at a deleted row. */
it('clears the pointer when a prune removes every message', function (): void {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    $only = messaging()->messages()->sendMessage($thread, $user, 'ancient');
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
