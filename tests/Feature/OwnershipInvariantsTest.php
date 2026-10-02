<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * A group thread has exactly one owner, and a direct thread has no roles at all — whatever
 * `messages.permissions.enabled` says. Roles are the thread's data: switching enforcement off
 * stops them being checked, not being kept. Before this, a thread created with roles off had
 * no owner (so switching roles on locked everyone out of it), any member could "transfer"
 * ownership and leave two owners behind, and a DM could be handed owner/admin roles.
 */
beforeEach(function () {
    $this->alice = User::create();
    $this->bob = User::create();
    $this->carol = User::create();
});

function ownersOf(Thread $thread): int
{
    return $thread->participants()->where('role', ParticipantRole::Owner->value)->count();
}

describe('with roles switched off', function () {
    beforeEach(function () {
        config()->set('messages.permissions.enabled', false);

        $this->group = Messages::start('Crew')->withParticipants([$this->alice, $this->bob, $this->carol])->create();
    });

    it('still gives a new group thread its owner and members', function () {
        expect($this->group->roleOf($this->alice))->toBe(ParticipantRole::Owner)
            ->and($this->group->roleOf($this->bob))->toBe(ParticipantRole::Member)
            ->and($this->group->roleOf($this->carol))->toBe(ParticipantRole::Member);
    });

    it('adds later participants as members', function () {
        $dave = User::create();

        Messages::thread($this->group)->participants()->add($dave);

        expect($this->group->roleOf($dave))->toBe(ParticipantRole::Member);
    });

    it('refuses a member transferring an ownership they do not hold', function () {
        expect(fn () => Messages::thread($this->group)->participants()->transferOwnership(from: $this->carol, to: $this->bob))
            ->toThrow(ParticipationException::class, 'does not own');

        expect(ownersOf($this->group))->toBe(1)
            ->and($this->group->roleOf($this->alice))->toBe(ParticipantRole::Owner)
            ->and($this->group->roleOf($this->carol))->toBe(ParticipantRole::Member);
    });

    it('lets the owner hand ownership over, leaving one owner', function () {
        Messages::thread($this->group)->participants()->transferOwnership(from: $this->alice, to: $this->bob);

        expect(ownersOf($this->group))->toBe(1)
            ->and($this->group->roleOf($this->bob))->toBe(ParticipantRole::Owner)
            ->and($this->group->roleOf($this->alice))->toBe(ParticipantRole::Admin);
    });

    it('keeps the thread manageable once roles are switched on', function () {
        config()->set('messages.permissions.enabled', true);

        expect(Messages::thread($this->group)->rename('Renamed', by: $this->alice)->name)->toBe('Renamed')
            ->and($this->group->canManage($this->bob))->toBeFalse();
    });
});

it('treats a transfer to the current owner as a no-op', function () {
    $group = Messages::start('Crew')->withParticipants([$this->alice, $this->bob])->create();

    $owner = Messages::thread($group)->participants()->transferOwnership(from: $this->alice, to: $this->alice);

    expect($owner->role)->toBe(ParticipantRole::Owner)
        ->and(ownersOf($group))->toBe(1);
});

describe('on a direct thread', function () {
    beforeEach(function () {
        $this->dm = Messages::direct($this->alice, $this->bob);
    });

    it('refuses to transfer an ownership a direct thread does not have', function (bool $enforced) {
        config()->set('messages.permissions.enabled', $enforced);

        expect(fn () => Messages::thread($this->dm)->participants()->transferOwnership(from: $this->alice, to: $this->bob))
            ->toThrow(ParticipationException::class, 'no roles');

        expect($this->dm->participants()->whereNotNull('role')->count())->toBe(0);
    })->with(['roles on' => true, 'roles off' => false]);

    it('refuses to set a role', function (?string $by) {
        expect(fn () => Messages::thread($this->dm)->participants()->setRole($this->bob, ParticipantRole::Admin, by: $by === null ? null : $this->alice))
            ->toThrow(ParticipationException::class, 'no roles');

        expect(Participant::query()->whereNotNull('role')->count())->toBe(0);
    })->with(['as a participant' => 'alice', 'trusted' => null]);
});
