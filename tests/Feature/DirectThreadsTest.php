<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
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

    $group = messaging()->threads()->create(name: 'Group');
    messaging()->participants()->addParticipantToThread($group, $alice);
    messaging()->participants()->addParticipantToThread($group, $bob);
    messaging()->participants()->addParticipantToThread($group, $carol);

    $dm = app(FindOrCreateDirectThread::class)->execute($alice, $bob);

    expect($dm->getKey())->not->toBe($group->getKey())
        ->and($dm->is_direct)->toBeTrue();
});

it('does not match a two-member group thread that is not direct', function () {
    $alice = User::create();
    $bob = User::create();

    $group = messaging()->threads()->create(name: 'Pair group');
    messaging()->participants()->addParticipantToThread($group, $alice);
    messaging()->participants()->addParticipantToThread($group, $bob);

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
