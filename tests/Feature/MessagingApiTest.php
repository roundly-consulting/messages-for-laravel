<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('finds or creates the direct thread with direct()', function () {
    $a = User::create();
    $b = User::create();

    $first = Messages::direct($a, $b);
    $second = Messages::direct($a, $b);

    expect($first->is_direct)->toBeTrue()
        ->and($first->getKey())->toBe($second->getKey());
});

it('lists conversations newest first via the trait', function () {
    $user = User::create();
    $older = $user->startConversationWith(User::create(), 'Older');
    $newer = $user->startConversationWith(User::create(), 'Newer');

    $older->forceFill(['last_activity_at' => now()->subDay()])->save();
    $newer->forceFill(['last_activity_at' => now()])->save();

    $conversations = $user->conversations();

    expect($conversations->first()->getKey())->toBe($newer->getKey())
        ->and($user->threads()->count())->toBe(2);
});

it('reports unread threads and unread counts', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $sender->startConversationWith($reader, 'Crew');

    app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'one'));
    app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'two'));

    expect($reader->unreadCount())->toBe(2)
        ->and($reader->unreadCount($thread))->toBe(2)
        ->and($reader->unreadThreads())->toHaveCount(1);

    $reader->markThreadRead($thread);

    expect($reader->unreadCount())->toBe(0)
        ->and($reader->unreadThreads())->toHaveCount(0);
});

it('marks a thread read for a participant via the thread', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $sender->startConversationWith($reader, 'Crew');

    app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'hi'));

    $participant = $thread->markReadFor($reader);

    expect($participant->read_at)->not->toBeNull()
        ->and($thread->unreadCountFor($reader))->toBe(0);
});

it('reports whether a message has been read by a participant', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $sender->startConversationWith($reader, 'Crew');

    $message = app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'hi'));

    expect($message->isReadBy($reader))->toBeFalse();

    $thread->markReadFor($reader);

    expect($message->fresh()->isReadBy($reader))->toBeTrue();
});

it('broadcasts a typing signal only when broadcasting is enabled', function () {
    Event::fake([ParticipantTyping::class]);

    $a = User::create();
    $b = User::create();
    $thread = $a->startConversationWith($b, 'Crew');

    config()->set('messages.broadcasting.enabled', false);
    $thread->typing($a);
    Event::assertNotDispatched(ParticipantTyping::class);

    config()->set('messages.broadcasting.enabled', true);
    $thread->typing($a);
    Event::assertDispatched(ParticipantTyping::class);
});

it('previews the latest message in a type-aware way', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->create();

    expect($thread->latestMessagePreview())->toBeNull();

    app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'a normal message'));
    expect($thread->fresh()->latestMessagePreview())->toBe('a normal message');

    app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: null,
        body: 'messages::messages.system.thread_renamed',
        type: MessageType::System,
        meta: ['name' => 'New name'],
    ));

    expect($thread->fresh()->latestMessagePreview())->toBe('The conversation was renamed to New name.');
});

it('previews the latest message when the relation is already loaded', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->create();
    app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'loaded'));

    $loaded = $thread->load('latestMessage');

    expect($loaded->latestMessagePreview())->toBe('loaded');
});

it('counts unread across all threads through the manager', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $sender->startConversationWith($reader, 'Crew');
    app(SendMessage::class)->execute(new SendMessageData($thread, $sender, 'hi'));

    expect(Messages::unreadCount($reader))->toBe(1)
        ->and(Messages::unreadCount($reader, $thread))->toBe(1);
});
