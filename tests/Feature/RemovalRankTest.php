<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
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
