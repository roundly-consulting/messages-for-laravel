<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('defaults a message type to text and casts the enum', function () {
    $thread = Messages::start('Chat')->create();
    $message = Messages::send($thread, User::create(), 'hi');

    expect($message->refresh()->type)->toBe(MessageType::Text);
});

it('casts a system message meta to an array', function () {
    $thread = Messages::start('Chat')->create();
    $message = Message::factory()->inThread($thread)->system()->create(['meta' => ['k' => 'v']]);

    expect($message->refresh()->meta)->toBe(['k' => 'v'])
        ->and($message->type)->toBe(MessageType::System);
});

it('casts thread flags including is_direct', function () {
    $thread = Thread::factory()->direct()->create();

    expect($thread->refresh()->is_direct)->toBeTrue()
        ->and($thread->is_public)->toBeFalse();
});
