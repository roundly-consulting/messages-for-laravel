<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create();
    $this->thread = messaging()->threads()->create(name: 'Hello Everyone!');
    $this->participant = messaging()->participants()->addParticipantToThread($this->thread, $this->user);
});

it('returns thread from participant', function () {
    expect($this->participant->thread->getKey())->toBe($this->thread->getKey());
});

it('throws exception when participant entity does not implement ParticipatesInMessaging interface', function () {
    $participant = messaging()->participants()->addParticipantToThread($this->thread, Restaurant::create());

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
