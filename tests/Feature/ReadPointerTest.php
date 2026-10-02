<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Read state follows the `last_read_message_id` pointer, not the second-precision `read_at`
 * stamp. Comparing timestamps lost every reply that landed in the same second as the read:
 * it counted as read, showed as "seen", and vanished from the unread badge — routine in an
 * active chat.
 */
beforeEach(function () {
    config()->set('messages.permissions.enabled', true);

    $this->alice = User::create();
    $this->carol = User::create();
    $this->dm = Messages::direct($this->alice, $this->carol);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('keeps a reply sent in the same second as the read unread', function () {
    Carbon::setTestNow('2026-09-28 12:00:00.100000');
    $first = $this->alice->sendMessageTo($this->dm, 'first');
    $this->carol->markThreadRead($this->dm);

    Carbon::setTestNow('2026-09-28 12:00:00.900000');
    $late = $this->alice->sendMessageTo($this->dm, 'sent after carol read');

    $inbox = collect(Messages::inboxFor($this->carol)->items())->firstWhere('id', $this->dm->getKey());

    expect($this->carol->unreadCount($this->dm))->toBe(1)
        ->and($this->carol->unreadCount())->toBe(1)
        ->and($inbox->unread_count)->toBe(1)
        ->and($late->isReadBy($this->carol))->toBeFalse()
        ->and($this->dm->seenBy($late)->pluck('participant_id')->all())->not->toContain($this->carol->getKey())
        ->and($first->isReadBy($this->carol))->toBeTrue()
        ->and($this->dm->seenBy($first)->pluck('participant_id')->all())->toContain($this->carol->getKey());
});

it('treats a read of an empty thread as having read nothing', function () {
    Carbon::setTestNow('2026-09-28 12:00:00.100000');
    $this->carol->markThreadRead($this->dm);

    Carbon::setTestNow('2026-09-28 12:00:00.900000');
    $message = $this->alice->sendMessageTo($this->dm, 'hello');

    expect($this->carol->unreadCount($this->dm))->toBe(1)
        ->and($message->isReadBy($this->carol))->toBeFalse();
});

it('marks read up to the newest message even through a stale thread instance', function () {
    $stale = Thread::query()->findOrFail($this->dm->getKey());

    $this->alice->sendMessageTo($this->dm, 'one');
    $newest = $this->alice->sendMessageTo($this->dm, 'two');

    $participant = Messages::markRead($stale, $this->carol);

    expect((string) $participant->last_read_message_id)->toBe((string) $newest->getKey())
        ->and($this->carol->unreadCount($this->dm))->toBe(0);
});

it('falls back to the read stamp once the pointer message has been pruned', function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $old = $this->alice->sendMessageTo($this->dm, 'old');
    $pointer = $this->alice->sendMessageTo($this->dm, 'read up to here');
    $this->carol->markThreadRead($this->dm);

    Carbon::setTestNow('2026-09-28 12:10:00');
    $fresh = $this->alice->sendMessageTo($this->dm, 'after');

    $pointer->forceDelete();

    expect($this->carol->unreadCount($this->dm))->toBe(1)
        ->and($old->isReadBy($this->carol))->toBeTrue()
        ->and($fresh->isReadBy($this->carol))->toBeFalse();
});

it('keeps an unsent pointer message as the read position', function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $first = $this->alice->sendMessageTo($this->dm, 'first');
    $pointer = $this->alice->sendMessageTo($this->dm, 'second');
    $this->carol->markThreadRead($this->dm);

    Messages::message($pointer)->delete(by: $this->alice);
    $late = $this->alice->sendMessageTo($this->dm, 'third');

    expect($this->carol->unreadCount($this->dm))->toBe(1)
        ->and($first->isReadBy($this->carol))->toBeTrue()
        ->and($late->isReadBy($this->carol))->toBeFalse();
});

it('finds the participants who have read up to a message with a query scope', function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $message = $this->alice->sendMessageTo($this->dm, 'hi');
    $this->carol->markThreadRead($this->dm);

    expect(Participant::query()->readUpTo($message)->pluck('participant_id')->all())
        ->toBe([$this->carol->getKey()]);
});

it('builds a read participant whose pointer sits on the newest message', function () {
    $thread = Thread::factory()->create();
    Message::factory()->inThread($thread)->count(2)->create();
    $newest = Message::factory()->inThread($thread)->create();

    $read = Participant::factory()->inThread($thread)->read()->create();
    $unread = Participant::factory()->inThread($thread)->unread()->create();

    expect((string) $read->last_read_message_id)->toBe((string) $newest->getKey())
        ->and($read->read_at)->not->toBeNull()
        ->and($unread->last_read_message_id)->toBeNull()
        ->and($thread->seenBy($newest)->modelKeys())->toBe([$read->getKey()]);
});
