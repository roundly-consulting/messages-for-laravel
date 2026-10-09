<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Fixtures\QueryRecorder;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Removing someone takes a role above theirs, and a group thread never loses its owner except
 * through transferOwnership(). Before this, an admin could remove the owner (or a fellow admin),
 * and the owner could simply leave — either way the thread was left with nobody able to change
 * roles again, ever.
 */
beforeEach(function () {
    config()->set('messages.permissions.enabled', true);

    $this->owner = User::create();
    $this->admin = User::create();
    $this->otherAdmin = User::create();
    $this->member = User::create();

    $this->thread = Messages::start('Crew')
        ->withParticipants([$this->owner, $this->admin, $this->otherAdmin, $this->member])
        ->create();

    $this->participants = Messages::thread($this->thread)->participants();
    $this->participants->setRole($this->admin, ParticipantRole::Admin, by: $this->owner);
    $this->participants->setRole($this->otherAdmin, ParticipantRole::Admin, by: $this->owner);
});

it('refuses an admin removing the owner', function () {
    expect(fn () => $this->participants->remove($this->owner, by: $this->admin))
        ->toThrow(UnauthorizedMessagingAction::class);

    expect($this->thread->roleOf($this->owner))->toBe(ParticipantRole::Owner);
});

it('refuses an admin removing a fellow admin', function () {
    expect(fn () => $this->participants->remove($this->otherAdmin, by: $this->admin))
        ->toThrow(UnauthorizedMessagingAction::class);

    expect($this->thread->roleOf($this->otherAdmin))->toBe(ParticipantRole::Admin);
});

it('lets an admin remove a member', function () {
    $this->participants->remove($this->member, by: $this->admin);

    expect($this->thread->roleOf($this->member))->toBeNull();
});

it('lets the owner remove an admin', function () {
    $this->participants->remove($this->admin, by: $this->owner);

    expect($this->thread->roleOf($this->admin))->toBeNull();
});

it('refuses the owner leaving while others remain', function () {
    expect(fn () => $this->participants->leave($this->owner))
        ->toThrow(ParticipationException::class, 'transfer');

    expect($this->thread->roleOf($this->owner))->toBe(ParticipantRole::Owner);
});

it('refuses a trusted caller removing the owner while others remain', function () {
    expect(fn () => $this->participants->remove($this->owner))
        ->toThrow(ParticipationException::class, 'transfer');

    expect($this->thread->roleOf($this->owner))->toBe(ParticipantRole::Owner);
});

it('lets the owner leave once ownership has moved on', function () {
    $this->participants->transferOwnership(from: $this->owner, to: $this->admin);

    $this->participants->leave($this->owner);

    expect($this->thread->roleOf($this->owner))->toBeNull()
        ->and($this->thread->roleOf($this->admin))->toBe(ParticipantRole::Owner);
});

it('lets the last participant — the owner — leave', function () {
    $solo = Messages::start('Solo')->withParticipants([$this->owner])->create();

    Messages::thread($solo)->participants()->leave($this->owner);

    expect($solo->participants()->count())->toBe(0);
});

it('keeps the owner in place with roles off, where every participant is a peer', function () {
    config()->set('messages.permissions.enabled', false);

    expect(fn () => $this->participants->remove($this->owner, by: $this->member))
        ->toThrow(ParticipationException::class, 'transfer');

    // Peers otherwise manage one another freely.
    $this->participants->remove($this->admin, by: $this->member);

    expect($this->thread->roleOf($this->admin))->toBeNull();
});

it('lets either side of a roleless direct thread leave', function () {
    $dm = Messages::direct($this->owner, $this->member);

    Messages::thread($dm)->participants()->leave($this->owner);

    expect($dm->participants()->count())->toBe(1);
});

/**
 * The owner may leave last — but on a thread open to everyone, the next person to join used to
 * get `member`, and with no owner nobody could rename, archive, add, remove, re-role or transfer
 * ownership again. The next participant into an ownerless group thread becomes its owner.
 */
describe('a group thread whose owner left last', function (): void {
    beforeEach(function (): void {
        $this->open = Messages::start('Open')->everyoneCanJoin()->withParticipants([$this->owner])->create();
        Messages::thread($this->open)->participants()->leave($this->owner);
    });

    it('makes the next one to join its owner', function (): void {
        $this->member->joinThread($this->open);

        expect($this->open->roleOf($this->member))->toBe(ParticipantRole::Owner)
            ->and(Messages::thread($this->open)->rename('Ours now', by: $this->member)->name)->toBe('Ours now');
    });

    it('makes a participant a trusted caller adds its owner', function (): void {
        Messages::thread($this->open)->participants()->add($this->admin);

        expect($this->open->roleOf($this->admin))->toBe(ParticipantRole::Owner);
    });

    it('gives everyone after the new owner the member role', function (): void {
        $this->member->joinThread($this->open);
        $this->admin->joinThread($this->open);

        expect($this->open->roleOf($this->admin))->toBe(ParticipantRole::Member)
            ->and($this->open->participants()->where('role', ParticipantRole::Owner->value)->count())->toBe(1);
    });
});

it('still adds a member to a thread that has its owner', function () {
    $newcomer = User::create();

    expect($this->participants->add($newcomer)->role)->toBe(ParticipantRole::Member);
});

/**
 * Removal reads "does anyone else remain?" and then deletes the owner's row. A join landing in
 * between saw the owner still there (so took `member`), and then the owner was gone: an ownerless
 * thread again. Removal now takes the same thread-row lock as adding, before that check.
 */
it('locks the thread row before it checks the owner can go', function () {
    $solo = Messages::start('Solo')->withParticipants([$this->owner])->create();

    $log = QueryRecorder::during(fn () => Messages::thread($solo)->participants()->leave($this->owner));

    $othersRemain = QueryRecorder::first($log, static fn (string $sql): bool => str_starts_with($sql, 'select exists')
        && str_contains($sql, 'from messaging_participants ')
        && (str_contains($sql, '!=') || str_contains($sql, '<>')));

    expect($othersRemain)->not->toBeNull()
        ->and(QueryRecorder::lockHeldAt($log, (int) $othersRemain, 'messaging_threads'))->toBeTrue();
});
