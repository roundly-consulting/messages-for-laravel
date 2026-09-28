<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Builders\PendingMessage;
use RoundlyConsulting\Messages\Builders\PendingThread;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('builds a private thread with a single participant', function () {
    $alice = User::create();

    /** @var PendingThread $pending */
    $pending = Messages::start();
    $thread = $pending
        ->named('Secret')
        ->private()
        ->withParticipant($alice)
        ->create();

    expect($thread->name)->toBe('Secret')
        ->and($thread->is_public)->toBeFalse()
        ->and($thread->participants()->count())->toBe(1);
});

it('builds a direct thread through the builder', function () {
    $thread = Messages::start()->direct()->create();

    expect($thread->is_direct)->toBeTrue();
});

it('sends a typed message through the builder', function () {
    $thread = Messages::start('Chat')->create();

    /** @var PendingMessage $pending */
    $pending = Messages::to($thread);
    $message = $pending->ofType(MessageType::System)->send('system note');

    expect($message->type)->toBe(MessageType::System)
        ->and($message->sender_id)->toBeNull();
});
