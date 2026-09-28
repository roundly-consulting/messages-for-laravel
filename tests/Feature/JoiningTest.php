<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Events\ParticipantJoined;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', true);

    $this->owner = User::create();
    $this->bob = User::create();
});

describe('adding someone who is already a participant', function (): void {
    it('returns the existing row instead of adding a second one', function (): void {
        Event::fake([ParticipantJoined::class]);
        $thread = Messages::start('Crew')->withParticipant($this->owner)->create();
        $participants = Messages::thread($thread)->participants();

        $first = $participants->add($this->bob, by: $this->owner);
        $second = $participants->add($this->bob, ParticipantRole::Admin, by: $this->owner);

        expect($second->getKey())->toBe($first->getKey())
            ->and($second->wasRecentlyCreated)->toBeFalse()
            ->and($second->role)->toBe(ParticipantRole::Member)
            ->and($thread->participants()->whereMorphedTo('participant', $this->bob)->count())->toBe(1);
        Event::assertDispatchedTimes(ParticipantJoined::class, 2); // the owner at start, bob once
    });

    it('adds a participant listed twice at start only once', function (): void {
        $thread = Messages::start('Crew')->withParticipants([$this->owner, $this->bob, $this->bob])->create();

        expect($thread->participants()->count())->toBe(2)
            ->and($thread->roleOf($this->owner))->toBe(ParticipantRole::Owner);
    });

    it('keeps a note-to-self direct thread to one row and finds it again', function (): void {
        $first = Messages::direct($this->owner, $this->owner);
        $second = Messages::direct($this->owner, $this->owner);

        expect($second->getKey())->toBe($first->getKey())
            ->and($first->participants()->count())->toBe(1);
    });

    it('adds a fresh row for someone who left and comes back', function (): void {
        $thread = Messages::start('Crew')->withParticipants([$this->owner, $this->bob])->create();

        Messages::thread($thread)->participants()->leave($this->bob);
        $back = Messages::thread($thread)->participants()->add($this->bob, by: $this->owner);

        expect($back->wasRecentlyCreated)->toBeTrue()
            ->and($thread->participants()->whereMorphedTo('participant', $this->bob)->count())->toBe(1)
            ->and(Participant::query()->withTrashed()->whereMorphedTo('participant', $this->bob)->count())->toBe(2);
    });

    it('does not count a repeated add as added in the fake', function (): void {
        $thread = Messages::start('Crew')->withParticipants([$this->owner, $this->bob])->create();
        $fake = Messages::fake();

        Messages::thread($thread)->participants()->add($this->bob, by: $this->owner);

        $fake->assertNothingAdded();
    });
});

describe('everyone_can_join', function (): void {
    it('refuses a self-join of a thread that is not open to everyone', function (): void {
        $thread = Messages::start('Closed')->withParticipant($this->owner)->create();

        expect(fn () => $this->bob->joinThread($thread))->toThrow(UnauthorizedMessagingAction::class, 'join')
            ->and(fn () => Messages::thread($thread)->participants()->add($this->bob, by: $this->bob))
            ->toThrow(UnauthorizedMessagingAction::class, 'join');

        expect($thread->participants()->whereMorphedTo('participant', $this->bob)->exists())->toBeFalse();
    });

    it('lets anyone join a thread that is open to everyone', function (): void {
        $thread = Messages::start('Open')->everyoneCanJoin()->withParticipant($this->owner)->create();

        $joined = $this->bob->joinThread($thread);
        $carol = User::create();
        Messages::thread($thread)->participants()->add($carol, by: $carol);

        expect($joined->wasRecentlyCreated)->toBeTrue()
            ->and($thread->roleOf($this->bob))->toBe(ParticipantRole::Member)
            ->and($thread->roleOf($carol))->toBe(ParticipantRole::Member);
    });

    it('refuses a stranger joining a direct thread', function (): void {
        $dm = Messages::direct($this->owner, $this->bob);

        User::create()->joinThread($dm);
    })->throws(UnauthorizedMessagingAction::class);

    it('is a no-op for someone already in a closed thread', function (): void {
        $thread = Messages::start('Closed')->withParticipants([$this->owner, $this->bob])->create();

        $row = $this->bob->joinThread($thread);

        expect($row->wasRecentlyCreated)->toBeFalse()
            ->and($thread->participants()->count())->toBe(2);
    });

    it('still lets a manager or a trusted caller add people to a closed thread', function (): void {
        $thread = Messages::start('Closed')->withParticipant($this->owner)->create();
        $carol = User::create();

        Messages::thread($thread)->participants()->add($this->bob, by: $this->owner);
        Messages::thread($thread)->participants()->add($carol);

        expect($thread->participants()->count())->toBe(3);
    });
});

describe('adding with a role', function (): void {
    beforeEach(function (): void {
        $this->admin = User::create();
        $this->thread = Messages::start('Crew')->everyoneCanJoin()->withParticipants([$this->owner, $this->admin])->create();
        Messages::thread($this->thread)->participants()->setRole($this->admin, ParticipantRole::Admin, by: $this->owner);
    });

    it('never adds a second owner, whoever asks', function (?string $by): void {
        $actor = $by === null ? null : $this->{$by};

        expect(fn () => Messages::thread($this->thread)->participants()->add($this->bob, ParticipantRole::Owner, by: $actor))
            ->toThrow(ParticipationException::class, 'transfer');

        expect($this->thread->roleOf($this->bob))->toBeNull();
    })->with(['owner' => 'owner', 'admin' => 'admin', 'trusted caller' => null]);

    it('leaves adding admins to the owner', function (): void {
        expect(fn () => Messages::thread($this->thread)->participants()->add($this->bob, ParticipantRole::Admin, by: $this->admin))
            ->toThrow(UnauthorizedMessagingAction::class);

        Messages::thread($this->thread)->participants()->add($this->bob, ParticipantRole::Admin, by: $this->owner);

        expect($this->thread->roleOf($this->bob))->toBe(ParticipantRole::Admin);
    });

    it('lets an admin add members', function (): void {
        Messages::thread($this->thread)->participants()->add($this->bob, by: $this->admin);

        expect($this->thread->roleOf($this->bob))->toBe(ParticipantRole::Member);
    });

    it('refuses a self-join that asks for a higher role', function (): void {
        Messages::thread($this->thread)->participants()->add($this->bob, ParticipantRole::Admin, by: $this->bob);
    })->throws(UnauthorizedMessagingAction::class);
});
