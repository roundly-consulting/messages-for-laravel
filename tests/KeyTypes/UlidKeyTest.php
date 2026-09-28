<?php

declare(strict_types=1);

use Carbon\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Fixtures\LatestMessageVectors;
use RoundlyConsulting\Messages\Tests\Models\User;

/** The seam's ulid leg. @see UuidKeyTest */
it('mints ulid primary keys and tracks them across the internal foreign keys', function (): void {
    $user = User::create();
    $thread = Messages::start('Hello')->create();
    Messages::thread($thread)->participants()->add($user);

    $message = Messages::send($thread, $user, 'first');
    $reply = Messages::to($thread)->from($user)->replyingTo($message)->send('second');

    expect($thread->id)->toBeString()->toHaveLength(26)
        ->and($thread->getKeyType())->toBe('string')
        ->and($thread->getIncrementing())->toBeFalse()
        ->and($thread->uniqueIds())->toBe(['id'])
        ->and($message->id)->toBeString()->toHaveLength(26)
        ->and($reply->parent_message_id)->toBe($message->id)
        ->and($message->thread_id)->toBe($thread->id);
});

/** `Str::ulid()` is time-ordered with a monotonic counter within a millisecond. */
it('keeps the id desc tiebreak monotonic on ulid keys', function (): void {
    Carbon::setTestNow('2026-07-17 12:00:00');

    $user = User::create();
    $thread = Messages::start('Hello')->create();
    Messages::thread($thread)->participants()->add($user);

    Messages::send($thread, $user, 'first');
    $last = Messages::send($thread, $user, 'second');

    expect($thread->fresh()->latestMessage->message)->toBe('second')
        ->and($thread->fresh()->latestMessage->id)->toBe($last->id);

    Carbon::setTestNow();
});

/**
 * The full latest-message vector table on the ulid key. `Str::ulid()` is time-ordered with a
 * monotonic within-millisecond counter, so the `id` desc tiebreak holds and the pointer lands
 * on the same answers as bigint and uuid.
 *
 * @see LatestMessageVectors
 */
afterEach(fn () => Carbon::setTestNow());

it('resolves the latest message on ulid keys', function (callable $scenario): void {
    [$thread, $expected] = $scenario();

    LatestMessageVectors::assertScenario($thread, $expected);
})->with(LatestMessageVectors::scenarios());
