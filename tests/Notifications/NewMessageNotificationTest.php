<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Listeners\NotifyParticipantsOfNewMessage;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\Messages\Tests\Models\NotifiableUser;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('notifies other notifiable participants when enabled', function () {
    config()->set('messages.notifications.enabled', true);
    Notification::fake();

    $sender = NotifiableUser::create();
    $recipient = NotifiableUser::create();

    $thread = $sender->startConversationWith($recipient, 'Crew');

    $sender->sendMessageTo($thread, 'hello');

    Notification::assertSentTo($recipient, NewMessageNotification::class);
    Notification::assertNotSentTo($sender, NewMessageNotification::class);
});

it('sends nothing when notifications are disabled', function () {
    config()->set('messages.notifications.enabled', false);
    Notification::fake();

    $sender = NotifiableUser::create();
    $recipient = NotifiableUser::create();
    $thread = $sender->startConversationWith($recipient, 'Crew');

    $sender->sendMessageTo($thread, 'hello');

    Notification::assertNothingSent();
});

it('skips participants that are not notifiable', function () {
    config()->set('messages.notifications.enabled', true);
    Notification::fake();

    $sender = NotifiableUser::create();
    $plain = User::create();
    $thread = $sender->startConversationWith($plain, 'Crew');

    $sender->sendMessageTo($thread, 'hi');

    Notification::assertCount(0);
});

it('does nothing when no notifiable recipients remain', function () {
    config()->set('messages.notifications.enabled', true);
    Notification::fake();

    $sender = NotifiableUser::create();
    $thread = $sender->startConversationWith($sender, 'Solo');

    $sender->sendMessageTo($thread, 'hi');

    Notification::assertNothingSent();
});

it('does nothing for a message whose thread is missing', function () {
    config()->set('messages.notifications.enabled', true);
    Notification::fake();

    $message = new Message;

    app(NotifyParticipantsOfNewMessage::class)->handle(
        new MessageSent($message),
    );

    Notification::assertNothingSent();
});

it('builds the notification payload and channels from config', function () {
    config()->set('messages.notifications.channels', ['mail', 'database']);

    $user = User::create();
    $thread = Messages::start('Chat')->create();
    $message = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'payload',
    ));

    $notification = new NewMessageNotification($message);
    $recipient = NotifiableUser::create();

    expect($notification->via($recipient))->toBe(['mail', 'database']);

    $payload = $notification->toArray($recipient);
    expect($payload)
        ->message_id->toBe($message->getKey())
        ->thread_id->toBe($message->thread_id)
        ->preview->toBe('payload');
});
