<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Events\ThreadCreated;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('creates a thread, adds participants and dispatches events', function () {
    Event::fake();

    $alice = User::create();
    $bob = User::create();

    $thread = app(StartThread::class)->execute(new CreateThreadData(
        name: 'Project',
        participants: [$alice, $bob],
    ));

    expect($thread)->toBeInstanceOf(Thread::class)
        ->and($thread->participants()->count())->toBe(2);

    Event::assertDispatched(ThreadCreated::class, fn (ThreadCreated $e): bool => $e->thread->is($thread));
    Event::assertDispatchedTimes(ParticipantJoined::class, 2);
});

it('forces direct threads to be private and unjoinable', function () {
    $thread = app(StartThread::class)->execute(new CreateThreadData(
        isPublic: true,
        everyoneCanJoin: true,
        isDirect: true,
    ));

    expect($thread->is_direct)->toBeTrue()
        ->and($thread->is_public)->toBeFalse()
        ->and($thread->everyone_can_join)->toBeFalse()
        ->and($thread->name)->toBeNull();
});

it('falls back to publicity config defaults', function () {
    config()->set('messages.publicity.public-by-default', true);
    config()->set('messages.publicity.everyone-can-join', true);

    $thread = app(StartThread::class)->execute(new CreateThreadData(name: 'Open'));

    expect($thread->is_public)->toBeTrue()
        ->and($thread->everyone_can_join)->toBeTrue();
});
