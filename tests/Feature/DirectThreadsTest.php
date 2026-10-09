<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Exceptions\ParticipationException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\Company;
use RoundlyConsulting\Messages\Tests\Models\User;

it('creates a direct thread between two participants', function () {
    $alice = User::create();
    $bob = User::create();

    $thread = app(FindOrCreateDirectThread::class)->execute($alice, $bob);

    expect($thread->is_direct)->toBeTrue()
        ->and($thread->name)->toBeNull()
        ->and($thread->participants()->count())->toBe(2);
});

it('reuses the same direct thread on subsequent calls', function () {
    $alice = User::create();
    $bob = User::create();

    $first = app(FindOrCreateDirectThread::class)->execute($alice, $bob);
    $second = app(FindOrCreateDirectThread::class)->execute($bob, $alice);

    expect($second->getKey())->toBe($first->getKey());
});

it('does not match a group thread with the same two members', function () {
    $alice = User::create();
    $bob = User::create();
    $carol = User::create();

    $group = Messages::start('Group')->create();
    Messages::thread($group)->participants()->add($alice);
    Messages::thread($group)->participants()->add($bob);
    Messages::thread($group)->participants()->add($carol);

    $dm = app(FindOrCreateDirectThread::class)->execute($alice, $bob);

    expect($dm->getKey())->not->toBe($group->getKey())
        ->and($dm->is_direct)->toBeTrue();
});

it('does not match a two-member group thread that is not direct', function () {
    $alice = User::create();
    $bob = User::create();

    $group = Messages::start('Pair group')->create();
    Messages::thread($group)->participants()->add($alice);
    Messages::thread($group)->participants()->add($bob);

    $dm = app(FindOrCreateDirectThread::class)->execute($alice, $bob);

    expect($dm->getKey())->not->toBe($group->getKey());
});

it('supports a polymorphic participant pair', function () {
    $user = User::create();
    $company = Company::create();

    $thread = app(FindOrCreateDirectThread::class)->execute($user, $company);
    $again = app(FindOrCreateDirectThread::class)->execute($user, $company);

    expect($thread->getKey())->toBe($again->getKey())
        ->and($thread->participants()->count())->toBe(2);
});

/**
 * A DM is the two of them. Any participant could add a third — direct threads have no roles, so
 * every participant passes the manage check — and the newcomer read the pair's whole history in
 * a thread that still answered as their DM. A direct thread now takes no new participants, from
 * anyone: bringing someone in means starting a group.
 */
describe('an existing direct thread', function (): void {
    beforeEach(function (): void {
        $this->alice = User::create();
        $this->bob = User::create();
        $this->carol = User::create();
        $this->dm = Messages::direct($this->alice, $this->bob);
        Messages::send($this->dm, $this->bob, 'just between us');
    });

    it('refuses a third participant', function (?string $by): void {
        $participants = Messages::thread($this->dm)->participants();

        expect(fn () => $participants->add($this->carol, by: $by === null ? null : $this->{$by}))
            ->toThrow(ParticipationException::class, ParticipationException::directThreadIsClosed()->getMessage());

        expect($this->dm->participants()->withTrashed()->count())->toBe(2)
            ->and($this->dm->fresh()->direct_key)->toBe(Thread::directKeyFor($this->alice, $this->bob))
            ->and(Messages::direct($this->alice, $this->bob)->getKey())->toBe($this->dm->getKey());
    })->with([
        'by a participant' => 'alice',
        'by a trusted caller' => null,
    ]);

    it('refuses someone who left coming back', function (): void {
        Messages::thread($this->dm)->participants()->leave($this->bob);

        expect(fn () => Messages::thread($this->dm)->participants()->add($this->bob))
            ->toThrow(ParticipationException::class);

        expect($this->dm->participants()->count())->toBe(1);
    });

    it('still returns the row of someone already in it', function (): void {
        $row = Messages::thread($this->dm)->participants()->add($this->bob);

        expect($row->wasRecentlyCreated)->toBeFalse()
            ->and($this->dm->participants()->count())->toBe(2);
    });
});
