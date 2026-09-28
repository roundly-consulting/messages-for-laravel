<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Events\ThreadRead;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create();
    $this->thread = Messages::start('Hello Everyone!')->create();
    $this->participant = Messages::thread($this->thread)->participants()->add($this->user);
});

it('returns thread from participant', function () {
    expect($this->participant->thread->getKey())->toBe($this->thread->getKey());
});

it('throws exception when participant entity does not implement ParticipatesInMessaging interface', function () {
    $participant = Messages::thread($this->thread)->participants()->add(Restaurant::create());

    $participant->broadcastWith('created');
})->expectException(ParticipationException::class);

it('returns custom data for broadcasting', function () {
    $this->freezeTime(function (Carbon $datetime) {
        expect($this->participant->broadcastWith('created'))
            ->toBe([
                'id' => $this->participant->id,
                'participant' => [
                    'id' => $this->user->id,
                ],
                'read_at' => null,
                'joined_at' => $datetime->toDateTimeString(),
                'left_at' => null,
            ]);
    });
});

it('returns no channels to broadcast on when broadcasting is turned off', function () {
    config()->set('messages.broadcasting.enabled', false);

    expect($this->participant->broadcastOn('created'))->toBe([]);
});

it('returns private channel to broadcast on', function () {
    config()->set('messages.broadcasting.enabled', true);

    $channel = $this->participant->broadcastOn('created');

    expect($channel)
        ->toBeInstanceOf(PrivateChannel::class)
        ->name->toBe('private-messaging.thread.'.$this->thread->getKey());
});

it('returns event name for broadcasting', function () {
    config()->set('messages.broadcasting.enabled', true);

    expect($this->participant->broadcastAs('created'))->toBe('messaging.participant.joined');
});

it('marks itself read through the manager, firing ThreadRead', function () {
    Event::fake([ThreadRead::class]);
    $sender = User::create();
    Messages::thread($this->thread)->participants()->add($sender);
    Messages::send($this->thread, $sender, 'hi');

    $returned = $this->participant->markAsRead();

    expect($returned)->toBe($this->participant)
        ->and($this->participant->read_at)->not->toBeNull()
        ->and($this->participant->isDirty())->toBeFalse()
        ->and($this->participant->last_read_message_id)->toBe($this->thread->fresh()->last_message_id);
    Event::assertDispatched(ThreadRead::class);
});

it('refuses to mark read for a participant whose model is gone', function () {
    $this->user->delete();

    $this->participant->fresh()->markAsRead();
})->throws(ParticipationException::class);
