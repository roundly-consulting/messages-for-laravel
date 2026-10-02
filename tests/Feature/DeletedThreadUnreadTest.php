<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * A deleted thread is gone from the inbox and from `unreadThreads()`, so its messages must not
 * keep the global unread badge up either — before this, `unreadCount()` still counted them and
 * the badge showed messages nobody could find.
 */
beforeEach(function () {
    $this->alice = User::create();
    $this->bob = User::create();

    $this->kept = Messages::start('Kept')->withParticipants([$this->alice, $this->bob])->create();
    $this->deleted = Messages::start('Deleted')->withParticipants([$this->alice, $this->bob])->create();

    $this->alice->sendMessageTo($this->kept, 'still here');
    $this->alice->sendMessageTo($this->deleted, 'one');
    $this->alice->sendMessageTo($this->deleted, 'two');

    $this->deleted->delete();
});

it('leaves a deleted thread out of the global unread count', function () {
    $inboxTotal = collect(Messages::inboxFor($this->bob)->items())->sum('unread_count');

    expect(Messages::unreadCount($this->bob))->toBe(1)
        ->and($this->bob->unreadCount())->toBe(1)
        ->and($inboxTotal)->toBe(1)
        ->and($this->bob->unreadThreads())->toHaveCount(1)
        ->and(Message::query()->unreadFor($this->bob)->pluck('message')->all())->toBe(['still here']);
});

it('counts the thread again once it is restored', function () {
    $this->deleted->restore();

    expect(Messages::unreadCount($this->bob))->toBe(3);
});
