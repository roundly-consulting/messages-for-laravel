<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('creates thread', function () {
    $this->freezeTime(function (Carbon $datetime) {
        $thread = Messages::start('Hello Everyone!')->create();

        expect($thread)->toBeInstanceOf(Thread::class);

        $this->assertDatabaseHas('messaging_threads', [
            'name' => 'Hello Everyone!',
            'is_public' => false,
            'everyone_can_join' => false,
            'last_activity_at' => $datetime,
        ]);
    });
});

it('creates thread and uses config values as defaults for visibility and allowance to join',
    function (string $name, bool $public, bool $canJoin) {
        config()->set('messages.publicity.public-by-default', $public);
        config()->set('messages.publicity.everyone-can-join', $canJoin);

        Messages::start($name)->create();

        $this->assertDatabaseHas('messaging_threads', [
            'name' => $name,
            'is_public' => $public,
            'everyone_can_join' => $canJoin,
        ]);
    })->with([
        ['Public', true, true],
        ['Readonly', true, false],
        ['Fully closed', false, false],
    ]);

it('creates thread that is publicly viewable', function () {
    $this->freezeTime(function (Carbon $datetime) {
        $thread = Messages::start('Readonly public chat')->public()->create();

        expect($thread)->toBeInstanceOf(Thread::class);

        $this->assertDatabaseHas('messaging_threads', [
            'name' => 'Readonly public chat',
            'is_public' => true,
            'everyone_can_join' => false,
            'last_activity_at' => $datetime,
        ]);
    });
});

it('creates thread that is publicly viewable and people can join', function () {
    $this->freezeTime(function (Carbon $datetime) {
        $thread = Messages::start('Readonly public chat')->public()->everyoneCanJoin()->create();

        expect($thread)->toBeInstanceOf(Thread::class);

        $this->assertDatabaseHas('messaging_threads', [
            'name' => 'Readonly public chat',
            'is_public' => true,
            'everyone_can_join' => true,
            'last_activity_at' => $datetime,
        ]);
    });
});

it('paginates public threads', function () {
    Messages::start('Public announcement')->public()->create();
    Messages::start('Public announcement')->private()->create();

    $threads = Messages::threads();

    expect($threads)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($threads->getCollection())
        ->count()
        ->toBe(1)
        ->first()
        ->toBeInstanceOf(Thread::class)
        ->first()->name
        ->toBe('Public announcement');
});

/**
 * The order here changed with the tiebreak added to `Messages::threads()`, and the
 * old expectation was an artifact rather than a contract.
 *
 * Both threads are created in the same second, so they tie on `last_activity_at` — the only
 * column the listing sorted by. The order between them was therefore undefined, and this
 * test passed for the package's life only because SQLite happened to return tied rows in
 * physical insertion order. It asserted the *older* thread first from a list whose declared
 * intent is newest-first, and it went red on Postgres roughly 1 run in 24.
 *
 * The listing now breaks ties on the uuid7 key, so the newest thread — 'Very secret
 * channel', created second — sorts first, deterministically. See
 * tests/Feature/ThreadOrderingTest.php for the determinism pin.
 */
it('paginates threads', function () {
    $user = User::create();
    $anotherUser = User::create();

    Messages::start('Public announcement')->public()->create();

    $nonPublicThread = Messages::start('Very secret channel')->private()->create();
    Messages::thread($nonPublicThread)->participants()->add($user);

    $threads = Messages::threads($user);

    expect($threads)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($threads->getCollection())
        ->count()
        ->toBe(2)
        ->first()
        ->toBeInstanceOf(Thread::class)
        ->first()->name
        ->toBe('Very secret channel')
        ->last()->name
        ->toBe('Public announcement');

    $threads = Messages::threads(for: $anotherUser);

    expect($threads)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($threads->getCollection())
        ->count()
        ->toBe(1)
        ->and($threads->getCollection()->first())
        ->toBeInstanceOf(Thread::class)
        ->name
        ->toBe('Public announcement')
        ->relationLoaded('latestMessage')
        ->toBeTrue();
});
