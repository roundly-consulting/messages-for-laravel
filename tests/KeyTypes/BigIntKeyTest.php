<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * The default.
 *
 * A thread and a message are things other packages point *at* polymorphically, and every raw
 * `morphs()` in the fleet emits an unsigned bigint — so a non-bigint default here makes them
 * unrelatable on any engine that actually checks types. They shipped as `uuid`.
 */
it('defaults to auto-incrementing bigint primary keys', function (): void {
    $thread = messaging()->threads()->create(name: 'Hello');

    expect($thread->id)->toBeInt()
        ->and($thread->getKeyType())->toBe('int')
        ->and($thread->getIncrementing())->toBeTrue()
        ->and($thread->uniqueIds())->toBe([]);
});

it('emits integer id columns across every messaging table', function (): void {
    $integerish = ['integer', 'bigint', 'int8'];

    expect(Schema::getColumnType('messaging_threads', 'id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_messages', 'id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'id'))->toBeIn($integerish);
});

/**
 * The four internal foreign keys. These are the reason `messages` is the largest of the three
 * packages in this fix: if any one of them fails to track the primary key, the package breaks
 * against *itself* — a FK constraint between mismatched column types is uncreatable, so the
 * migration does not even run.
 */
/**
 * The outbound morph axis default. `messages.key_type` governs the sender and participant
 * morph ids; unset, it defaults to bigint exactly like the raw `morphs()`/`nullableMorphs()`
 * it replaced — so a default install's morph columns are byte-identical integers. This is
 * the *other* axis from the pk above, and on the default both land on bigint.
 */
it('defaults the outbound sender and participant morph ids to bigint', function (): void {
    $integerish = ['integer', 'bigint', 'int8'];

    expect(Schema::getColumnType('messaging_messages', 'sender_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'participant_id'))->toBeIn($integerish);
});

it('tracks the primary key across all four internal foreign keys', function (): void {
    $integerish = ['integer', 'bigint', 'int8'];

    expect(Schema::getColumnType('messaging_messages', 'thread_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_messages', 'parent_message_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'thread_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'last_read_message_id'))->toBeIn($integerish);
});

it('sends, threads and replies across the bigint foreign keys', function (): void {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    $message = messaging()->messages()->sendMessage($thread, $user, 'first');
    $reply = Messages::to($thread)->from($user)->replyingTo($message)->send('second');

    expect($reply->parent_message_id)->toBe($message->id)
        ->and($reply->thread_id)->toBe($thread->id)
        ->and($thread->messages()->count())->toBe(2);
});

/**
 * A post/credit/thread being pointed at by a bigint morph column is the whole point of the
 * default — `anything↔messages` is the same shape as the proven `posts↔likes` vector.
 */
it('produces an id a bigint morph column can hold', function (): void {
    $thread = messaging()->threads()->create(name: 'Hello');

    Schema::create('bigint_morph_probe', function ($table): void {
        $table->id();
        $table->morphs('subject');
    });

    $stored = $thread->getConnection()->table('bigint_morph_probe')->insertGetId([
        'subject_type' => $thread->getMorphClass(),
        'subject_id' => $thread->getKey(),
    ]);

    expect($stored)->toBeGreaterThan(0);
});

/**
 * The `id desc` tiebreak on `Thread::latestMessage()` was justified by `HasUuids` minting
 * time-ordered `Str::uuid7()`. Flipping the default to bigint changes that justification, so
 * it is re-proven here rather than assumed: an auto-increment sequence is monotonic by
 * construction, which is a *stronger* guarantee than uuid7's (a property of the minting
 * algorithm rather than of the database).
 *
 * Ties are the normal case, not the edge: Laravel stores timestamps at second precision and
 * `setTestNow` freezes the clock, so all three messages below share one `created_at` and the
 * tiebreak is the only thing deciding the winner.
 */
it('keeps the id desc tiebreak monotonic on bigint keys', function (): void {
    Carbon::setTestNow('2026-07-17 12:00:00');

    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    messaging()->messages()->sendMessage($thread, $user, 'first');
    messaging()->messages()->sendMessage($thread, $user, 'second');
    $last = messaging()->messages()->sendMessage($thread, $user, 'third');

    $ids = Message::query()->where('thread_id', $thread->id)->pluck('id')->all();

    expect($ids)->toBe(array_values(collect($ids)->sort()->all()))
        ->and($thread->fresh()->latestMessage->id)->toBe($last->id)
        ->and($thread->fresh()->latestMessage->message)->toBe('third');

    Carbon::setTestNow();
});
