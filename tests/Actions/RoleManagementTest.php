<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\AddParticipant;
use RoundlyConsulting\Messages\Actions\ArchiveThread;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Actions\RemoveParticipant;
use RoundlyConsulting\Messages\Actions\RenameThread;
use RoundlyConsulting\Messages\Actions\SetParticipantRole;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\Actions\TransferOwnership;
use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\RemoveParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SetParticipantRoleData;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ThreadArchived;
use RoundlyConsulting\Messages\Events\ThreadRenamed;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Tests\Models\User;

function crew(User $owner, User ...$members)
{
    return app(StartThread::class)->execute(new CreateThreadData(
        name: 'Crew',
        participants: [$owner, ...$members],
    ));
}

it('assigns owner and member roles when starting a group thread', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    expect($thread->roleOf($owner))->toBe(ParticipantRole::Owner)
        ->and($thread->roleOf($member))->toBe(ParticipantRole::Member);
});

it('sets a participant role as a manager', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    $participant = app(SetParticipantRole::class)->execute(new SetParticipantRoleData(
        thread: $thread,
        participant: $member,
        role: ParticipantRole::Admin,
        actor: $owner,
    ));

    expect($participant->role)->toBe(ParticipantRole::Admin)
        ->and($thread->roleOf($member))->toBe(ParticipantRole::Admin);
});

it('forbids a member from changing roles', function () {
    $owner = User::create();
    $member = User::create();
    $other = User::create();
    $thread = crew($owner, $member, $other);

    app(SetParticipantRole::class)->execute(new SetParticipantRoleData(
        thread: $thread,
        participant: $other,
        role: ParticipantRole::Admin,
        actor: $member,
    ));
})->throws(UnauthorizedMessagingAction::class);

it('throws when setting a role for a non-participant', function () {
    $owner = User::create();
    $thread = crew($owner);

    app(SetParticipantRole::class)->execute(new SetParticipantRoleData(
        thread: $thread,
        participant: User::create(),
        role: ParticipantRole::Admin,
    ));
})->throws(ParticipationException::class);

it('transfers ownership and demotes the previous owner to admin', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    $next = app(TransferOwnership::class)->execute($thread, $owner, $member);

    expect($next->role)->toBe(ParticipantRole::Owner)
        ->and($thread->roleOf($member))->toBe(ParticipantRole::Owner)
        ->and($thread->roleOf($owner))->toBe(ParticipantRole::Admin);
});

it('forbids a non-owner from transferring ownership', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(TransferOwnership::class)->execute($thread, $member, $owner);
})->throws(UnauthorizedMessagingAction::class);

it('throws when transferring ownership to a non-participant', function () {
    $owner = User::create();
    $thread = crew($owner);

    app(TransferOwnership::class)->execute($thread, $owner, User::create());
})->throws(ParticipationException::class);

it('renames a thread and dispatches ThreadRenamed', function () {
    Event::fake([ThreadRenamed::class]);

    $owner = User::create();
    $thread = crew($owner);

    app(RenameThread::class)->execute($thread, 'Renamed', $owner);

    expect($thread->fresh()->name)->toBe('Renamed');
    Event::assertDispatched(ThreadRenamed::class);
});

it('writes a system message when renaming with system messages enabled', function () {
    config()->set('messages.system-messages.enabled', true);

    $owner = User::create();
    $thread = crew($owner);

    app(RenameThread::class)->execute($thread, 'Renamed', $owner);

    expect($thread->messages()->where('type', 'system')->where('message', 'messages::messages.system.thread_renamed')->exists())->toBeTrue();
});

it('forbids a member from renaming a thread', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(RenameThread::class)->execute($thread, 'Nope', $member);
})->throws(UnauthorizedMessagingAction::class);

it('archives a thread once and dispatches ThreadArchived', function () {
    Event::fake([ThreadArchived::class]);

    $owner = User::create();
    $thread = crew($owner);

    app(ArchiveThread::class)->execute($thread, $owner);

    expect($thread->fresh()->isArchived())->toBeTrue();
    Event::assertDispatchedTimes(ThreadArchived::class, 1);

    // Archiving again is a no-op and dispatches nothing further.
    app(ArchiveThread::class)->execute($thread->fresh(), $owner);
    Event::assertDispatchedTimes(ThreadArchived::class, 1);
});

it('forbids a member from archiving a thread', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(ArchiveThread::class)->execute($thread, $member);
})->throws(UnauthorizedMessagingAction::class);

it('forbids a member from adding participants', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(AddParticipant::class)->execute(new AddParticipantData(
        thread: $thread,
        participant: User::create(),
        actor: $member,
    ));
})->throws(UnauthorizedMessagingAction::class);

it('forbids a member from removing another participant', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(RemoveParticipant::class)->execute(new RemoveParticipantData(
        thread: $thread,
        participant: $owner,
        actor: $member,
    ));
})->throws(UnauthorizedMessagingAction::class);

it('lets a member leave (remove themselves) without manage rights', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    app(RemoveParticipant::class)->execute(new RemoveParticipantData(
        thread: $thread,
        participant: $member,
        actor: $member,
    ));

    expect($thread->participants()->whereMorphedTo('participant', $member)->exists())->toBeFalse();
});

it('forbids a member from deleting another participants message', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    $message = $thread->messages()->create([
        'sender_id' => $owner->getKey(),
        'sender_type' => $owner->getMorphClass(),
        'message' => 'hi',
    ]);

    app(DeleteMessage::class)->execute($message, $member);
})->throws(UnauthorizedMessagingAction::class);

it('lets an admin delete another participants message', function () {
    $owner = User::create();
    $member = User::create();
    $thread = crew($owner, $member);

    $message = $thread->messages()->create([
        'sender_id' => $member->getKey(),
        'sender_type' => $member->getMorphClass(),
        'message' => 'hi',
    ]);

    app(DeleteMessage::class)->execute($message, $owner);

    expect($message->fresh()->trashed())->toBeTrue();
});

it('adds participants without roles on a direct thread', function () {
    $a = User::create();
    $b = User::create();
    $thread = app(FindOrCreateDirectThread::class)->execute($a, $b);

    expect($thread->roleOf($a))->toBeNull()
        ->and($thread->roleOf($b))->toBeNull()
        ->and($thread->is_direct)->toBeTrue();
});

it('builds an unauthorized exception with a translated message', function () {
    $actor = User::create();

    $exception = UnauthorizedMessagingAction::for($actor, 'do that');
    expect($exception->getMessage())->toContain('do that');

    $roleException = UnauthorizedMessagingAction::requiresRole($actor, ParticipantRole::Owner, 'do that');
    expect($roleException->getMessage())->toContain('owner');
});
