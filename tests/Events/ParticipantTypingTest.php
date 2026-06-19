<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('broadcasts on the thread channel when broadcasting is enabled', function () {
    config()->set('messages.broadcasting.enabled', true);

    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    $event = new ParticipantTyping($thread, $user);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastAs())->toBe('messaging.participant.typing');
});

it('does not broadcast when broadcasting is disabled', function () {
    config()->set('messages.broadcasting.enabled', false);

    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    expect((new ParticipantTyping($thread, $user))->broadcastOn())->toBe([]);
});

it('includes the participant payload', function () {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    $payload = (new ParticipantTyping($thread, $user))->broadcastWith();

    expect($payload)
        ->thread_id->toBe($thread->getKey())
        ->and($payload['participant'])->toBe(['id' => $user->id]);
});

it('rejects a non-participating model in the payload', function () {
    $thread = messaging()->threads()->create(name: 'Chat');

    (new ParticipantTyping($thread, Restaurant::create()))->broadcastWith();
})->throws(ParticipationException::class);
