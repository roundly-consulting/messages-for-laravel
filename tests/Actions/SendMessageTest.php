<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

it('sends a message, bumps activity and dispatches MessageSent', function () {
    Event::fake();

    $user = User::create();
    Carbon::setTestNow($created = now());
    $thread = Messages::start('Chat')->create();

    Carbon::setTestNow($sent = now()->addMinutes(3));
    $message = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'hello',
    ));

    expect($message->message)->toBe('hello')
        ->and($message->type)->toBe(MessageType::Text)
        ->and($thread->refresh()->last_activity_at->format('H:i'))->toBe($sent->format('H:i'))
        ->not->toBe($created->format('H:i'));

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e): bool => $e->message->is($message));

    Carbon::setTestNow();
});

it('stores system messages with meta and a null sender', function () {
    $thread = Messages::start('Chat')->create();

    $message = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: null,
        body: 'messages::messages.system.thread_renamed',
        type: MessageType::System,
        meta: ['name' => 'Renamed'],
    ));

    expect($message->type)->toBe(MessageType::System)
        ->and($message->sender_id)->toBeNull()
        ->and($message->meta)->toBe(['name' => 'Renamed']);
});

it('fires events even when broadcasting is disabled', function () {
    config()->set('messages.broadcasting.enabled', false);
    Event::fake();

    $thread = Messages::start('Chat')->create();
    app(SendMessage::class)->execute(new SendMessageData($thread, User::create(), 'hi'));

    Event::assertDispatched(MessageSent::class);
});
