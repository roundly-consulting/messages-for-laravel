<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Actions\FindOrCreateDirectThread;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

it('filters direct threads with the direct scope', function () {
    messaging()->threads()->create(name: 'Group');
    Thread::factory()->direct()->create();

    expect(Thread::query()->direct()->count())->toBe(1);
});

it('lists threads for a participant ordered by activity', function () {
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Mine');
    messaging()->participants()->addParticipantToThread($thread, $user);
    messaging()->threads()->create(name: 'Not mine');

    $threads = Thread::query()->forParticipant($user)->get();

    expect($threads)->toHaveCount(1)
        ->and($threads->first()->name)->toBe('Mine');
});

it('matches a direct thread with the between scope', function () {
    $alice = User::create();
    $bob = User::create();
    $dm = app(FindOrCreateDirectThread::class)->execute($alice, $bob);

    expect(Thread::query()->between($alice, $bob)->first()->getKey())->toBe($dm->getKey());
});

it('finds unread participants with the unread scope', function () {
    $thread = messaging()->threads()->create(name: 'Chat');
    Participant::factory()->inThread($thread)->unread()->create();
    Participant::factory()->inThread($thread)->read()->create();

    expect(Participant::query()->unread()->count())->toBe(1);
});

it('finds unread messages for a participant', function () {
    $alice = User::create();
    $bob = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');
    messaging()->participants()->addParticipantToThread($thread, $bob);
    messaging()->messages()->sendMessage($thread, $alice, 'unread one');

    expect(Message::query()->unreadFor($bob)->count())->toBe(1);
});
