<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('returns custom data for broadcasting', function () {
    $thread = Messages::start('Hello Everyone!')->create();

    expect($thread->broadcastWith('created'))
        ->toBe([
            'id' => $thread->id,
            'name' => 'Hello Everyone!',
            'is_public' => false,
            'everyone_can_join' => false,
        ]);
});

it('returns no channels to broadcast on when broadcasting is turned off or event is not created', function () {
    config()->set('messages.broadcasting.enabled', false);

    $thread = Messages::start('Hello Everyone!')->create();

    expect($thread->broadcastOn('created'))->toBe([]);

    config()->set('messages.broadcasting.enabled', true);

    expect($thread->broadcastOn('updated'))->toBe([]);
});

it('returns public channel to broadcast on when thread is public', function () {
    config()->set('messages.broadcasting.enabled', true);

    $thread = Messages::start('Hello Everyone!')->public()->create();

    expect($thread->broadcastOn('created'))
        ->toBeInstanceOf(Channel::class)
        ->name->toBe('messaging');
});

it('returns private channels to broadcast on when thread is private', function () {
    config()->set('messages.broadcasting.enabled', true);

    $thread = Messages::start('Hello Everyone!')->private()->create();

    Messages::thread($thread)->participants()->add(User::create());
    Messages::thread($thread)->participants()->add(User::create());

    $channels = $thread->refresh()->broadcastOn('created');

    expect($channels)
        ->toBeArray()
        ->toHaveCount(2)
        ->and($channels[0])
        ->toBeInstanceOf(PrivateChannel::class)
        ->name->toBe('private-messaging.participant.user.1')
        ->and($channels[1])
        ->toBeInstanceOf(PrivateChannel::class)
        ->name->toBe('private-messaging.participant.user.2');
});

it('returns event name for broadcasting', function () {
    config()->set('messages.broadcasting.enabled', true);

    $thread = Messages::start('Hello Everyone!')->private()->create();

    expect($thread->broadcastAs('created'))->toBe('messaging.thread.created');
});
