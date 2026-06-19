<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\MarkRead;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\Events\ThreadRead;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->alice = User::create();
    $this->bob = User::create();
    $this->thread = messaging()->threads()->create(name: 'Chat');
    messaging()->participants()->addParticipantToThread($this->thread, $this->alice);
    messaging()->participants()->addParticipantToThread($this->thread, $this->bob);
});

it('counts all messages as unread before any read', function () {
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'one');
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'two');

    expect($this->thread->unreadCountFor($this->bob))->toBe(2);
});

it('does not count the participant own messages as unread', function () {
    messaging()->messages()->sendMessage($this->thread, $this->bob, 'mine');

    expect($this->thread->unreadCountFor($this->bob))->toBe(0);
});

it('resets unread to zero after marking read and dispatches ThreadRead', function () {
    Event::fake();

    messaging()->messages()->sendMessage($this->thread, $this->alice, 'hi');

    $participant = app(MarkRead::class)->execute(new MarkReadData($this->thread, $this->bob));

    expect($this->thread->unreadCountFor($this->bob))->toBe(0)
        ->and($participant->read_at)->not->toBeNull()
        ->and($participant->last_read_message_id)->not->toBeNull();

    Event::assertDispatched(ThreadRead::class);
});

it('counts only messages sent after the read pointer', function () {
    Carbon::setTestNow(now());
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'before');
    app(MarkRead::class)->execute(new MarkReadData($this->thread, $this->bob));

    Carbon::setTestNow(now()->addMinutes(5));
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'after');

    expect($this->thread->unreadCountFor($this->bob))->toBe(1);

    Carbon::setTestNow();
});

it('marking read twice stays idempotent', function () {
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'hi');

    app(MarkRead::class)->execute(new MarkReadData($this->thread, $this->bob));
    app(MarkRead::class)->execute(new MarkReadData($this->thread, $this->bob));

    expect($this->thread->unreadCountFor($this->bob))->toBe(0);
});

it('throws when marking read for a non-participant', function () {
    app(MarkRead::class)->execute(new MarkReadData($this->thread, User::create()));
})->throws(ParticipationException::class);

it('lists participants who have seen a message', function () {
    Carbon::setTestNow(now());
    $message = messaging()->messages()->sendMessage($this->thread, $this->alice, 'hi');

    Carbon::setTestNow(now()->addMinute());
    app(MarkRead::class)->execute(new MarkReadData($this->thread, $this->bob));

    $seen = $this->thread->seenBy($message);

    expect($seen)->toHaveCount(1)
        ->and($seen->first()->participant_id)->toBe($this->bob->getKey());

    Carbon::setTestNow();
});

it('exposes participant helper methods for reading', function () {
    messaging()->messages()->sendMessage($this->thread, $this->alice, 'hi');

    $participant = $this->thread->participants()->whereMorphedTo('participant', $this->bob)->first();

    expect($participant->hasUnread())->toBeTrue();

    $participant->markAsRead();

    expect($participant->hasUnread())->toBeFalse();
});
