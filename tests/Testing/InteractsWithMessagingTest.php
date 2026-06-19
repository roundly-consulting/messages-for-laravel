<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Testing\InteractsWithMessaging;
use RoundlyConsulting\Messages\Tests\Models\User;

uses(InteractsWithMessaging::class);

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('starts a group conversation through the trait', function () {
    $a = User::create();
    $b = User::create();

    $thread = $this->startConversation($a, $b);

    expect($thread)->toHaveParticipant($a)->toHaveParticipant($b);
});

it('finds or creates a direct thread through the trait', function () {
    $a = User::create();
    $b = User::create();

    $thread = $this->directThread($a, $b);

    expect($thread->is_direct)->toBeTrue();
});

it('sends and marks messages read as the remembered actor', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $this->startConversation($sender, $reader);

    $message = $this->actingAsParticipant($sender)->sendMessageAs($thread, 'hello');

    expect($thread)->toHaveSentMessage('hello')->toHaveUnread($reader);

    $this->actingAsParticipant($reader)->markReadAs($thread);

    expect($thread->unreadCountFor($reader))->toBe(0);
});

it('sends a message to an explicitly passed actor', function () {
    $sender = User::create();
    $reader = User::create();
    $thread = $this->startConversation($sender, $reader);

    $this->sendMessageAs($thread, 'explicit', $sender);

    expect($thread)->toHaveSentMessage('explicit');
});

it('throws when no actor is set', function () {
    $thread = $this->startConversation(User::create());

    $this->sendMessageAs($thread, 'nope');
})->throws(RuntimeException::class);

it('exposes the toHaveRole and toBeReplyTo expectations', function () {
    config()->set('messages.permissions.enabled', true);

    $owner = User::create();
    $thread = $this->startConversation($owner);

    expect($thread)->toHaveRole($owner, ParticipantRole::Owner);

    config()->set('messages.permissions.enabled', false);

    $parent = $this->actingAsParticipant($owner)->sendMessageAs($thread, 'p');
    $reply = app(MessagesManager::class)
        ->to($thread)
        ->from($owner)
        ->replyingTo($parent)
        ->send('r');

    expect($reply)->toBeReplyTo($parent);
});
