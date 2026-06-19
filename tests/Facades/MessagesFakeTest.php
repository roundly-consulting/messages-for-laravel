<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Testing\MessagesFake;
use RoundlyConsulting\Messages\Tests\Models\User;

it('records messages sent through the fake while still persisting them', function () {
    $fake = Messages::fake();

    expect($fake)->toBeInstanceOf(MessagesFake::class);

    $alice = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    Messages::send($thread, $alice, 'one');
    Messages::send($thread, $alice, 'two');

    $fake->assertSent();
    $fake->assertSent('one');
    $fake->assertSentCount(2);

    expect($thread->messages()->count())->toBe(2);
});

it('asserts nothing was sent', function () {
    $fake = Messages::fake();

    $fake->assertNothingSent();
});

it('delegates other operations through the fake', function () {
    $fake = Messages::fake();

    $alice = User::create();
    $bob = User::create();

    $dm = Messages::direct($alice, $bob);
    Messages::send($dm, $alice, 'hi');

    expect(Messages::unreadCount($bob))->toBe(1);

    Messages::markRead($dm, $bob);

    expect(Messages::unreadCount($bob))->toBe(0)
        ->and(Messages::to($dm))->toBeInstanceOf(PendingMessage::class)
        ->and(Messages::thread('x'))->toBeInstanceOf(PendingThread::class);

    $fake->assertSentCount(1);
});
