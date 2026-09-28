<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ThreadCreated;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * A thread is announced only once its participants are in. The `created` broadcast used to be
 * computed inside `save()`, before any participant existed: a private thread reached no
 * channel at all, and the broadcast cached an empty `participants` relation on the very
 * instance `create()` returned — so its owner was refused the moment they tried to manage it.
 */
beforeEach(function () {
    config()->set('messages.permissions.enabled', true);
    config()->set('messages.broadcasting.enabled', true);

    $this->alice = User::create();
    $this->bob = User::create();

    $this->threadBroadcasts = [];

    Event::listen(BroadcastableModelEventOccurred::class, function (BroadcastableModelEventOccurred $event): void {
        if ($event->model instanceof Thread) {
            $this->threadBroadcasts[] = [
                'event' => $event->event(),
                // A lone Channel comes back flattened to its name by the framework's wrapper.
                'channels' => array_values(array_map(
                    static fn (Channel|string $channel): string => $channel instanceof Channel ? $channel->name : $channel,
                    $event->broadcastOn(),
                )),
            ];
        }
    });
});

it('broadcasts a new private thread to each of its participants', function () {
    Messages::start('Secret')->private()->withParticipants([$this->alice, $this->bob])->create();

    expect($this->threadBroadcasts)->toBe([[
        'event' => 'created',
        'channels' => [
            'private-messaging.participant.user.'.$this->alice->getKey(),
            'private-messaging.participant.user.'.$this->bob->getKey(),
        ],
    ]]);
});

it('broadcasts a new public thread once, on the public channel', function () {
    Messages::start('Open')->public()->withParticipants([$this->alice])->create();

    expect($this->threadBroadcasts)->toBe([['event' => 'created', 'channels' => ['messaging']]]);
});

it('broadcasts nothing while broadcasting is off', function () {
    config()->set('messages.broadcasting.enabled', false);

    Messages::start('Quiet')->withParticipants([$this->alice, $this->bob])->create();

    expect($this->threadBroadcasts)->toBe([]);
});

it('returns a thread its owner can manage straight away', function () {
    $thread = Messages::start('Secret')->private()->withParticipants([$this->alice, $this->bob])->create();

    expect($thread->participants)->toHaveCount(2)
        ->and($thread->roleOf($this->alice))->toBe(ParticipantRole::Owner)
        ->and($thread->canManage($this->alice))->toBeTrue()
        ->and(Messages::thread($thread)->rename('Renamed', by: $this->alice)->name)->toBe('Renamed');
});

it('dispatches ThreadCreated once the participants are in', function () {
    $seen = null;

    Event::listen(ThreadCreated::class, function (ThreadCreated $event) use (&$seen): void {
        $seen = $event->thread->participants()->count();
    });

    Messages::start('Team')->withParticipants([$this->alice, $this->bob])->create();

    expect($seen)->toBe(2);
});

it('creates the thread and its participants atomically', function () {
    $dispatched = false;

    Event::listen(ThreadCreated::class, function () use (&$dispatched): void {
        $dispatched = true;
    });

    // An unsaved model has no key, so its participant row violates NOT NULL.
    expect(fn () => Messages::start('Broken')->withParticipants([$this->alice, new User])->create())
        ->toThrow(QueryException::class);

    expect(Thread::query()->withTrashed()->count())->toBe(0)
        ->and(Participant::query()->withTrashed()->count())->toBe(0)
        ->and($dispatched)->toBeFalse()
        ->and($this->threadBroadcasts)->toBe([]);
});
