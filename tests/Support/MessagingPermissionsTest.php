<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Support\MessagingPermissions;
use RoundlyConsulting\Messages\Tests\Models\User;

function groupThread(User $owner, User ...$members)
{
    return app(StartThread::class)->execute(new CreateThreadData(
        name: 'Crew',
        participants: [$owner, ...$members],
    ));
}

it('is enabled by config', function () {
    config()->set('messages.permissions.enabled', true);
    expect(MessagingPermissions::enabled())->toBeTrue();

    config()->set('messages.permissions.enabled', false);
    expect(MessagingPermissions::enabled())->toBeFalse();
});

it('does not enforce on direct threads', function () {
    $a = User::create();
    $b = User::create();
    $thread = Messages::start('x')->create();
    $thread->forceFill(['is_direct' => true])->save();

    expect(MessagingPermissions::enforces($thread))->toBeFalse()
        ->and(MessagingPermissions::canManage($thread, $a))->toBeTrue()
        ->and(MessagingPermissions::canTransferOwnership($thread, $b))->toBeTrue();
});

it('does not enforce when disabled', function () {
    config()->set('messages.permissions.enabled', false);

    $owner = User::create();
    $member = User::create();
    $thread = groupThread($owner, $member);

    expect(MessagingPermissions::enforces($thread))->toBeFalse()
        ->and(MessagingPermissions::canManage($thread, $member))->toBeTrue();
});

it('lets owner and admin manage but not a member', function () {
    $owner = User::create();
    $admin = User::create();
    $member = User::create();
    $thread = groupThread($owner, $admin, $member);

    $thread->participants()
        ->whereMorphedTo('participant', $admin)
        ->first()
        ->forceFill(['role' => ParticipantRole::Admin])
        ->save();

    expect(MessagingPermissions::canManage($thread, $owner))->toBeTrue()
        ->and(MessagingPermissions::canManage($thread, $admin))->toBeTrue()
        ->and(MessagingPermissions::canManage($thread, $member))->toBeFalse();
});

it('only lets the owner transfer ownership', function () {
    $owner = User::create();
    $member = User::create();
    $thread = groupThread($owner, $member);

    expect(MessagingPermissions::canTransferOwnership($thread, $owner))->toBeTrue()
        ->and(MessagingPermissions::canTransferOwnership($thread, $member))->toBeFalse();
});

it('lets anyone delete their own message but only managers delete others', function () {
    $owner = User::create();
    $member = User::create();
    $thread = groupThread($owner, $member);

    $memberMessage = $thread->messages()->create([
        'sender_id' => $member->getKey(),
        'sender_type' => $member->getMorphClass(),
        'message' => 'mine',
    ]);

    /** @var Message $memberMessage */
    expect(MessagingPermissions::canDeleteMessage($thread, $member, $memberMessage))->toBeTrue()
        ->and(MessagingPermissions::canDeleteMessage($thread, $owner, $memberMessage))->toBeTrue();

    $ownerMessage = $thread->messages()->create([
        'sender_id' => $owner->getKey(),
        'sender_type' => $owner->getMorphClass(),
        'message' => 'theirs',
    ]);

    /** @var Message $ownerMessage */
    expect(MessagingPermissions::canDeleteMessage($thread, $member, $ownerMessage))->toBeFalse();
});

it('lets only the author edit a message, whatever the roles', function () {
    config()->set('messages.permissions.enabled', true);

    $owner = User::create();
    $member = User::create();
    $thread = groupThread($owner, $member);
    $message = Messages::send($thread, $member, 'mine');

    expect(MessagingPermissions::canEditMessage($message, $member))->toBeTrue()
        ->and(MessagingPermissions::canEditMessage($message, $owner))->toBeFalse();

    MessagingPermissions::authorizeEditMessage($message, $member);
    MessagingPermissions::authorizeEditMessage($message, $owner);
})->throws(UnauthorizedMessagingAction::class, 'edit this message');
