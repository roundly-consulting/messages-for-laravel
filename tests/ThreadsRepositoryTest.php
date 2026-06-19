<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('creates thread', function () {
    $this->freezeTime(function (Carbon $datetime) {
        $thread = messaging()->threads()->create(
            name: 'Hello Everyone!',
        );

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

        messaging()->threads()->create(name: $name);

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
        $thread = messaging()->threads()->create(
            name: 'Readonly public chat',
            isPublic: true,
        );

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
        $thread = messaging()->threads()->create(
            name: 'Readonly public chat',
            isPublic: true,
            everyoneCanJoin: true,
        );

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
    messaging()->threads()->create(name: 'Public announcement', isPublic: true);
    messaging()->threads()->create(name: 'Public announcement', isPublic: false);

    $threads = messaging()->threads()->paginate();

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

it('paginates threads', function () {
    $user = User::create();
    $anotherUser = User::create();

    messaging()->threads()->create(name: 'Public announcement', isPublic: true);

    $nonPublicThread = messaging()->threads()->create(name: 'Very secret channel', isPublic: false);
    messaging()->participants()->addParticipantToThread(thread: $nonPublicThread, participant: $user);

    $threads = messaging()->threads()->paginate(participant: $user);

    expect($threads)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($threads->getCollection())
        ->count()
        ->toBe(2)
        ->first()
        ->toBeInstanceOf(Thread::class)
        ->first()->name
        ->toBe('Public announcement')
        ->last()->name
        ->toBe('Very secret channel');

    $threads = messaging()->threads()->paginate(participant: $anotherUser);

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
