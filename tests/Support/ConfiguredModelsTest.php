<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\ParticipantModel;
use RoundlyConsulting\Messages\Support\ThreadModel;
use RoundlyConsulting\Messages\Tests\Models\CustomMessage;
use RoundlyConsulting\Messages\Tests\Models\CustomParticipant;
use RoundlyConsulting\Messages\Tests\Models\CustomThread;
use RoundlyConsulting\Messages\Tests\Models\NotAMessage;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('resolves the packaged models by default', function (): void {
    expect(ThreadModel::class())->toBe(Thread::class)
        ->and(MessageModel::class())->toBe(Message::class)
        ->and(ParticipantModel::class())->toBe(Participant::class);
});

it('resolves a host subclass configured in config', function (): void {
    config()->set('messages.models.thread', CustomThread::class);
    config()->set('messages.models.message', CustomMessage::class);
    config()->set('messages.models.participant', CustomParticipant::class);

    expect(ThreadModel::class())->toBe(CustomThread::class)
        ->and(MessageModel::class())->toBe(CustomMessage::class)
        ->and(ParticipantModel::class())->toBe(CustomParticipant::class);
});

it('throws when a configured model is not an eloquent model at all', function (): void {
    config()->set('messages.models.thread', 'NotAClass');

    ThreadModel::class();
})->throws(InvalidConfigurationException::class);

/**
 * The toolkit's ModelResolver validates "is a Model" — never "is *your* model". A real
 * Eloquent model that is not one of ours cannot answer the package's scopes, so the
 * package's own wrapper narrows and falls back to the packaged model.
 */
it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('messages.models.thread', NotAMessage::class);
    config()->set('messages.models.message', NotAMessage::class);
    config()->set('messages.models.participant', NotAMessage::class);

    expect(fn (): string => ThreadModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [messages.models.thread] must be a class-string of ['.Thread::class.'], ['.NotAMessage::class.'] given.',
    );
    expect(fn (): string => MessageModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [messages.models.message] must be a class-string of ['.Message::class.'], ['.NotAMessage::class.'] given.',
    );
    expect(fn (): string => ParticipantModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [messages.models.participant] must be a class-string of ['.Participant::class.'], ['.NotAMessage::class.'] given.',
    );
});

/**
 * REGRESSION — the appointments (#19) bug, live in this package.
 *
 * `Thread::participants()` / `messages()` were declared without an explicit foreign key, so
 * Eloquent derived it from the *parent's class name*. With a host subclass configured in
 * `messages.models.thread`, every relation looked for `custom_thread_id` instead of
 * `thread_id` and every insert and read broke — while the default path stayed green, which is
 * why it went unnoticed.
 *
 * `latestMessage()` is a `belongsTo` over the thread's own `last_message_id` pointer. It takes
 * an explicit key for the same reason: left implicit, Eloquent derives `latest_message_id`
 * from the relation method name.
 */
describe('a configured thread subclass', function (): void {
    beforeEach(function (): void {
        config()->set('messages.models.thread', CustomThread::class);
        config()->set('messages.models.message', CustomMessage::class);
        config()->set('messages.models.participant', CustomParticipant::class);
    });

    it('names the thread foreign key explicitly on every relation it owns', function (): void {
        $thread = new CustomThread;

        expect($thread->participants()->getForeignKeyName())->toBe('thread_id')
            ->and($thread->messages()->getForeignKeyName())->toBe('thread_id')
            ->and($thread->latestMessage()->getForeignKeyName())->toBe('last_message_id');
    });

    it('starts a thread, adds participants and sends messages through the subclass', function (): void {
        $alice = User::create();
        $bob = User::create();

        $thread = $alice->startConversationWith($bob, 'Design review');

        expect($thread)->toBeInstanceOf(CustomThread::class);

        $message = $alice->sendMessageTo($thread, 'Ship it');

        expect($message)->toBeInstanceOf(CustomMessage::class)
            ->and($thread->participants()->count())->toBe(2)
            ->and($thread->participants()->first())->toBeInstanceOf(CustomParticipant::class)
            ->and($thread->messages()->count())->toBe(1)
            ->and($thread->latestMessage()->first()?->getKey())->toBe($message->getKey());
    });

    it('tracks unread counts and read receipts through the subclass', function (): void {
        $alice = User::create();
        $bob = User::create();

        $thread = $alice->conversationWith($bob);
        $message = $alice->sendMessageTo($thread, 'Are you there?');

        expect($bob->unreadCount())->toBe(1)
            ->and($thread->unreadCountFor($bob))->toBe(1)
            ->and($message->isReadBy($bob))->toBeFalse();

        $bob->markThreadRead($thread);

        expect($bob->unreadCount())->toBe(0)
            ->and($message->isReadBy($bob))->toBeTrue();
    });

    it('threads replies through the subclass', function (): void {
        $alice = User::create();
        $bob = User::create();

        $thread = $alice->conversationWith($bob);
        $parent = $alice->sendMessageTo($thread, 'Original');

        $reply = Messages::to($thread)->from($bob)->replyingTo($parent)->send('Reply');

        expect($reply->parent?->getKey())->toBe($parent->getKey())
            ->and($parent->replies()->count())->toBe(1)
            ->and($parent->replies()->first())->toBeInstanceOf(CustomMessage::class);
    });

    it('serves the inbox through the subclass', function (): void {
        $alice = User::create();
        $bob = User::create();

        $thread = $alice->conversationWith($bob);
        $alice->sendMessageTo($thread, 'Inbox me');

        $inbox = Messages::inboxFor($bob);

        expect($inbox->total())->toBe(1)
            ->and($inbox->items()[0])->toBeInstanceOf(CustomThread::class)
            ->and($inbox->items()[0]->unread_count)->toBe(1);
    });
});
