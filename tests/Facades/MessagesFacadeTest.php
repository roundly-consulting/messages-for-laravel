<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('creates a thread through the fluent builder', function () {
    $alice = User::create();
    $bob = User::create();

    $thread = Messages::thread('Project X')
        ->public()
        ->everyoneCanJoin()
        ->withParticipants([$alice, $bob])
        ->create();

    expect($thread)->toBeInstanceOf(Thread::class)
        ->and($thread->is_public)->toBeTrue()
        ->and($thread->everyone_can_join)->toBeTrue()
        ->and($thread->participants()->count())->toBe(2);
});

it('returns pending builders from the facade', function () {
    $thread = messaging()->threads()->create(name: 'Chat');

    expect(Messages::thread('x'))->toBeInstanceOf(PendingThread::class)
        ->and(Messages::to($thread))->toBeInstanceOf(PendingMessage::class);
});

it('sends a message through the fluent builder', function () {
    $alice = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from($alice)->send('Hello');

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->message)->toBe('Hello');
});

it('exposes direct, send, markRead and unreadCount passthroughs', function () {
    $alice = User::create();
    $bob = User::create();

    $dm = Messages::direct($alice, $bob);
    Messages::send($dm, $alice, 'hi');

    expect(Messages::unreadCount($bob))->toBe(1)
        ->and(Messages::unreadCount($bob, $dm))->toBe(1);

    Messages::markRead($dm, $bob);

    expect(Messages::unreadCount($bob))->toBe(0);
});

it('matches the repository write path', function () {
    $alice = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    $viaFacade = Messages::send($thread, $alice, 'a');
    $viaRepo = messaging()->messages()->sendMessage($thread, $alice, 'b');

    expect($viaFacade)->toBeInstanceOf(Message::class)
        ->and($viaRepo)->toBeInstanceOf(Message::class)
        ->and($thread->messages()->count())->toBe(2);
});
