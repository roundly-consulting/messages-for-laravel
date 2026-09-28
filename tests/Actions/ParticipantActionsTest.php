<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\AddParticipant;
use RoundlyConsulting\Messages\Actions\LeaveThread;
use RoundlyConsulting\Messages\Actions\RemoveParticipant;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\RemoveParticipantData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Events\ParticipantLeft;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('adds a participant and dispatches ParticipantJoined', function () {
    Event::fake();

    $thread = Messages::start('Chat')->create();
    $user = User::create();

    $participant = app(AddParticipant::class)->execute(new AddParticipantData($thread, $user));

    expect($participant->participant_id)->toBe($user->getKey());

    Event::assertDispatched(ParticipantJoined::class);
});

it('removes a participant and dispatches ParticipantLeft', function () {
    Event::fake();

    $thread = Messages::start('Chat')->create();
    $user = User::create();
    app(AddParticipant::class)->execute(new AddParticipantData($thread, $user));

    app(RemoveParticipant::class)->execute(new RemoveParticipantData($thread, $user));

    expect($thread->participants()->count())->toBe(0)
        ->and($thread->participants()->withTrashed()->count())->toBe(1);

    Event::assertDispatched(ParticipantLeft::class);
});

it('throws when removing a non-participant', function () {
    $thread = Messages::start('Chat')->create();

    app(RemoveParticipant::class)->execute(new RemoveParticipantData($thread, User::create()));
})->throws(ParticipationException::class);

it('lets a participant leave a thread', function () {
    $thread = Messages::start('Chat')->create();
    $user = User::create();
    app(AddParticipant::class)->execute(new AddParticipantData($thread, $user));

    app(LeaveThread::class)->execute($thread, $user);

    expect($thread->participants()->count())->toBe(0);
});

it('writes join and leave system messages when enabled', function () {
    config()->set('messages.system-messages.enabled', true);

    $thread = Messages::start('Chat')->create();
    $user = User::create();

    app(AddParticipant::class)->execute(new AddParticipantData($thread, $user));
    app(RemoveParticipant::class)->execute(new RemoveParticipantData($thread, $user));

    $systemMessages = $thread->messages()->where('type', MessageType::System->value)->get();

    expect($systemMessages)->toHaveCount(2);
});

it('writes no system messages when disabled', function () {
    config()->set('messages.system-messages.enabled', false);

    $thread = Messages::start('Chat')->create();
    app(AddParticipant::class)->execute(new AddParticipantData($thread, User::create()));

    expect($thread->messages()->count())->toBe(0);
});
