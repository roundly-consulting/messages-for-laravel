<?php

declare(strict_types=1);

use Carbon\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

/** The seam's ulid leg. @see UuidKeyTest */
it('mints ulid primary keys and tracks them across the internal foreign keys', function (): void {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    $message = messaging()->messages()->sendMessage($thread, $user, 'first');
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
    $thread = messaging()->threads()->create(name: 'Hello');
    messaging()->participants()->addParticipantToThread($thread, $user);

    messaging()->messages()->sendMessage($thread, $user, 'first');
    $last = messaging()->messages()->sendMessage($thread, $user, 'second');

    expect($thread->fresh()->latestMessage->message)->toBe('second')
        ->and($thread->fresh()->latestMessage->id)->toBe($last->id);

    Carbon::setTestNow();
});
