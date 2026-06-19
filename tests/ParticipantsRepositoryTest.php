<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Tests\Models\User;

it('adds participant to thread', function () {
    Carbon::setTestNow(
        $freezedTime = now()
    );

    $user = User::create();

    $thread = messaging()->threads()->create(name: 'Very secret channel', isPublic: false);

    expect($thread->last_activity_at)
        ->toBeInstanceOf(Carbon::class)
        ->format('Y-m-d H:i')
        ->toBe($freezedTime->format('Y-m-d H:i'));

    Carbon::setTestNow(
        $lastActivityAt = now()->addMinutes(5),
    );

    $participant = messaging()->participants()->addParticipantToThread(thread: $thread, participant: $user);

    expect($participant)->toBeInstanceOf(Participant::class);

    expect($thread->refresh()->last_activity_at)
        ->toBeInstanceOf(Carbon::class)
        ->format('Y-m-d H:i')
        ->toBe($lastActivityAt->format('Y-m-d H:i'));

    $this->assertDatabaseHas('messaging_participants', [
        'participant_id' => $user->getKey(),
        'participant_type' => $user->getMorphClass(),
        'thread_id' => $thread->getKey(),
    ]);
});
