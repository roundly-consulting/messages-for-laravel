<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('builds a system message with replacements through the builder', function () {
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)
        ->asSystem('messages::messages.system.thread_renamed', ['name' => 'New'])
        ->withMeta(['extra' => 'value'])
        ->send();

    expect($message->type)->toBe(MessageType::System)
        ->and($message->sender_id)->toBeNull()
        ->and($message->message)->toBe('messages::messages.system.thread_renamed')
        ->and($message->meta['name'])->toBe('New')
        ->and($message->meta['extra'])->toBe('value');
});

it('attaches a sender through the builder', function () {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from($user)->send('hi');

    expect($message->sender_id)->toBe($user->getKey());
});
