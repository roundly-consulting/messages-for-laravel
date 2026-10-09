<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * A soft delete fires both `trashed` and `deleted`, and Laravel's model broadcasting listens to
 * both — so with the config mapping each to the same name, every unsend and every leave went out
 * twice. The `deleted` broadcast is kept for what only it means: a force delete.
 */
beforeEach(function (): void {
    config()->set('messages.broadcasting.enabled', true);

    $this->alice = User::create();
    $this->bob = User::create();
    $this->thread = Messages::start('Crew')->withParticipants([$this->alice, $this->bob])->create();

    $this->broadcasts = [];

    Event::listen(BroadcastableModelEventOccurred::class, function (BroadcastableModelEventOccurred $event): void {
        if (($event->model instanceof Message || $event->model instanceof Participant) && $event->broadcastOn() !== []) {
            $this->broadcasts[] = $event->broadcastAs();
        }
    });
});

it('broadcasts an unsend once', function (): void {
    $message = Messages::send($this->thread, $this->alice, 'oops');
    $this->broadcasts = [];

    Messages::message($message)->delete();

    expect($this->broadcasts)->toBe(['messaging.message.unsent']);
});

it('broadcasts a leave once', function (): void {
    Messages::thread($this->thread)->participants()->leave($this->bob);

    expect(array_values(array_filter($this->broadcasts, static fn (string $name): bool => $name === 'messaging.participant.left')))
        ->toBe(['messaging.participant.left']);
});

it('still broadcasts a force delete, once', function (): void {
    $message = Messages::send($this->thread, $this->alice, 'gone for good');
    $this->broadcasts = [];

    $message->forceDelete();

    expect($this->broadcasts)->toBe(['messaging.message.unsent']);
});

it('broadcasts a restore once', function (): void {
    $message = Messages::send($this->thread, $this->alice, 'back again');
    $message->delete();
    $this->broadcasts = [];

    $message->restore();

    expect(array_values(array_filter($this->broadcasts, static fn (string $name): bool => $name === 'messaging.message.restored')))
        ->toBe(['messaging.message.restored']);
});
