<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Tests\Models\NotifiableUser;
use RoundlyConsulting\Messages\Tests\Models\User;

it('reports the role of a participant from a loaded relation', function () {
    $owner = User::create();
    $member = User::create();
    $thread = app(StartThread::class)->execute(new CreateThreadData(
        name: 'Crew',
        participants: [$owner, $member],
    ));

    $loaded = $thread->load('participants.participant');

    expect($loaded->roleOf($owner))->toBe(ParticipantRole::Owner)
        ->and($loaded->roleOf($member))->toBe(ParticipantRole::Member);
});

it('answers canManage on the thread', function () {
    $owner = User::create();
    $member = User::create();
    $thread = app(StartThread::class)->execute(new CreateThreadData(
        name: 'Crew',
        participants: [$owner, $member],
    ));

    expect($thread->canManage($owner))->toBeTrue()
        ->and($thread->canManage($member))->toBeFalse();
});

it('returns notifiable participants excluding the sender', function () {
    config()->set('messages.permissions.enabled', false);

    $sender = NotifiableUser::create();
    $recipient = NotifiableUser::create();
    $thread = $sender->startConversationWith($recipient, 'Crew');

    $all = $thread->notifiableParticipants();
    $withoutSender = $thread->notifiableParticipants($sender);

    expect($all)->toHaveCount(2)
        ->and($withoutSender)->toHaveCount(1)
        ->and($withoutSender->first()->getKey())->toBe($recipient->getKey());
});

it('excludes non-notifiable participants', function () {
    config()->set('messages.permissions.enabled', false);

    $notifiable = NotifiableUser::create();
    $plain = User::create();
    $thread = $notifiable->startConversationWith($plain, 'Crew');

    expect($thread->notifiableParticipants())->toHaveCount(1);
});
