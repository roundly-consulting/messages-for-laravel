<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Fixtures\LatestMessageVectors;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The seam's uuid leg. A config-driven key type that only works on its default is not a
 * seam — the migrations and the models must agree on the same config value across three
 * tables and four internal foreign keys, or the migration cannot even create the FK.
 */
it('mints uuid primary keys and tracks them across the internal foreign keys', function (): void {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    $message = messaging()->messages()->sendMessage($thread, $user, 'first');
    $reply = Messages::to($thread)->from($user)->replyingTo($message)->send('second');

    expect($thread->id)->toBeString()->toHaveLength(36)
        ->and($thread->getKeyType())->toBe('string')
        ->and($thread->getIncrementing())->toBeFalse()
        ->and($thread->uniqueIds())->toBe(['id'])
        ->and($message->id)->toBeString()->toHaveLength(36)
        ->and($reply->parent_message_id)->toBe($message->id)
        ->and($message->thread_id)->toBe($thread->id);
});

it('emits string id columns across every messaging table', function (): void {
    expect(Schema::getColumnType('messaging_threads', 'id'))->toBeIn(['varchar', 'string', 'uuid'])
        ->and(Schema::getColumnType('messaging_messages', 'thread_id'))->toBeIn(['varchar', 'string', 'uuid']);
});

/**
 * The two-axes independence proof, inbound half. This leg sets ONLY the inbound
 * `primary_key_type` to uuid; the outbound `key_type` is left at its bigint default, so the
 * sender / participant morph ids must stay bigint even though the pk is now a uuid. Flipping
 * one axis must not drag the other.
 */
it('keeps the outbound morph ids bigint while the pk is uuid', function (): void {
    $integerish = ['integer', 'bigint', 'int8'];

    expect(Schema::getColumnType('messaging_messages', 'sender_id'))->toBeIn($integerish)
        ->and(Schema::getColumnType('messaging_participants', 'participant_id'))->toBeIn($integerish);
});

/**
 * The `id desc` tiebreak must stay monotonic on this leg too — here it rests on uuid7 being
 * time-ordered, which is the justification the bigint default replaces with a sequence.
 */
it('keeps the id desc tiebreak monotonic on uuid keys', function (): void {
    Carbon::setTestNow('2026-07-17 12:00:00');

    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    messaging()->messages()->sendMessage($thread, $user, 'first');
    $last = messaging()->messages()->sendMessage($thread, $user, 'second');

    expect($thread->fresh()->latestMessage->message)->toBe('second')
        ->and($thread->fresh()->latestMessage->id)->toBe($last->id);

    Carbon::setTestNow();
});

/**
 * The negative control, and the documented cost of the seam in executable form.
 *
 * A green key-type test proves nothing until you have watched the engine reject the broken
 * shape. `uuid` is supported — but only for a host whose morph targets are ALL uuid-keyed. A
 * raw `morphs()` column, which is what 22 of the fleet's packages emit, is an unsigned bigint
 * and cannot hold this id. That is exactly the failure that shipped as the default.
 *
 * Postgres-only on purpose: SQLite's type affinity stores the uuid string in an INTEGER
 * column without complaint, so this test CANNOT fail there. That silent acceptance is what
 * hid the bug fleet-wide, and a skip here is the honest report of it.
 */
it('cannot be stored in a bigint morph column — the cost of a non-bigint key', function (): void {
    $thread = messaging()->threads()->create(name: 'Hello');

    Schema::create('bigint_morph_probe', function ($table): void {
        $table->id();
        $table->morphs('subject');
    });

    expect(fn () => $thread->getConnection()->table('bigint_morph_probe')->insert([
        'subject_type' => $thread->getMorphClass(),
        'subject_id' => $thread->getKey(),
    ]))->toThrow(QueryException::class, 'invalid input syntax for type bigint');
})->skip(
    fn (): bool => DriverMatrix::driver() !== 'pgsql',
    'sqlite type affinity accepts the uuid silently — only a strict engine detects this',
);

/**
 * The full latest-message vector table on the uuid key. The pointer's correctness must not
 * depend on the key type: the recompute orders by `created_at` desc, `id` desc, and uuid7 is
 * time-ordered, so the same answers hold here as on bigint.
 *
 * @see LatestMessageVectors
 */
afterEach(fn () => Carbon::setTestNow());

it('resolves the latest message on uuid keys', function (callable $scenario): void {
    [$thread, $expected] = $scenario();

    LatestMessageVectors::assertScenario($thread, $expected);
})->with(LatestMessageVectors::scenarios());
