<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\SignalTyping;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('dispatches ParticipantTyping while broadcasting is on', function () {
    Event::fake([ParticipantTyping::class]);
    config()->set('messages.broadcasting.enabled', true);
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    app(SignalTyping::class)->execute($thread, $user);

    Event::assertDispatched(ParticipantTyping::class, fn (ParticipantTyping $event): bool => $event->participant->is($user));
});

it('does nothing while broadcasting is off', function () {
    Event::fake([ParticipantTyping::class]);
    config()->set('messages.broadcasting.enabled', false);
    $user = User::create();

    app(SignalTyping::class)->execute(Messages::start('Chat')->create(), $user);

    Event::assertNotDispatched(ParticipantTyping::class);
});
