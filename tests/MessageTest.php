<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->user = User::create();
    $this->thread = Messages::start('Hello Everyone!')->create();
    $this->message = Messages::send($this->thread, $this->user, 'Yay!');
});

it('returns thread from message', function () {
    expect($this->message->thread->getKey())->toBe($this->thread->getKey());
});

it('returns sender from message', function () {
    expect($this->message->sender->getKey())->toBe($this->user->getKey());
});

it('throws exception when sender entity does not implement ParticipatesInMessaging interface', function () {
    $message = Messages::send($this->thread, Restaurant::create(), 'Yay!');

    $message->broadcastWith('created');
})->expectException(ParticipationException::class);

it('returns custom data for broadcasting', function () {
    $this->freezeTime(function (Carbon $datetime) {
        expect($this->message->broadcastWith('created'))
            ->toBe([
                'id' => $this->message->id,
                'sender' => [
                    'id' => $this->user->id,
                ],
                'message' => 'Yay!',
                'sent_at' => $datetime->toDateTimeString(),
                'updated_at' => $datetime->toDateTimeString(),
                'deleted_at' => null,
            ]);
    });
});

it('returns no channels to broadcast on when broadcasting is turned off', function () {
    config()->set('messages.broadcasting.enabled', false);

    expect($this->message->broadcastOn('created'))->toBe([]);
});

it('returns private channel to broadcast on', function () {
    config()->set('messages.broadcasting.enabled', true);

    $channel = $this->message->broadcastOn('created');

    expect($channel)
        ->toBeInstanceOf(PrivateChannel::class)
        ->name->toBe('private-messaging.thread.'.$this->thread->getKey());
});

it('returns event name for broadcasting', function () {
    config()->set('messages.broadcasting.enabled', true);

    expect($this->message->broadcastAs('created'))->toBe('messaging.message.sent');
});
