<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', true);
});

it('asserts a thread has any message and a specific body', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->create();
    app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'specific body'));

    expect($thread)
        ->toHaveSentMessage()
        ->toHaveSentMessage('specific body');
});

it('asserts unread, participant and role expectations', function () {
    $owner = User::create();
    $reader = User::create();
    $thread = app(StartThread::class)->execute(
        new CreateThreadData(
            name: 'Crew',
            participants: [$owner, $reader],
        ),
    );

    app(SendMessage::class)->execute(new SendMessageData($thread, $owner, 'hi'));

    expect($thread)
        ->toHaveParticipant($reader)
        ->toHaveUnread($reader)
        ->toHaveRole($owner, ParticipantRole::Owner);
});

it('fails when a thread has no matching message', function () {
    $thread = Messages::start('Empty')->create();

    expect(fn () => expect($thread)->toHaveSentMessage())
        ->toThrow(AssertionFailedError::class);
});

it('fails when a thread has no matching body', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->create();
    app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'present'));

    expect(fn () => expect($thread)->toHaveSentMessage('absent'))
        ->toThrow(AssertionFailedError::class);
});
