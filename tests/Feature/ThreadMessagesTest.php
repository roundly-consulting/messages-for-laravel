<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('writes message to thread and bumps last activity', function () {
    $user = User::create();

    Carbon::setTestNow($createdAt = now());

    $thread = Messages::start('Important messages only')->withParticipant($user)->create();

    Carbon::setTestNow($sentAt = now()->addMinutes(5));

    $message = Messages::send($thread, $user, 'I know but...');

    expect($message)->toBeInstanceOf(Message::class)
        ->and($thread->refresh()->last_activity_at->format('Y-m-d H:i'))
        ->toBe($sentAt->format('Y-m-d H:i'))
        ->not->toBe($createdAt->format('Y-m-d H:i'));

    $this->assertDatabaseHas('messaging_messages', [
        'sender_id' => $user->getKey(),
        'sender_type' => $user->getMorphClass(),
        'thread_id' => $thread->getKey(),
        'message' => 'I know but...',
        'created_at' => $sentAt,
    ]);

    Carbon::setTestNow();
});

it('paginates messages from thread', function () {
    $user = User::create();

    $thread = Messages::start('Its Friday then Then Saturday, Sunday')->withParticipant($user)->create();

    $message = Messages::send($thread, $user, 'What!');

    $messagesForThread = Messages::thread($thread)->messages();

    expect($messagesForThread)
        ->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($messagesForThread->getCollection())
        ->toHaveLength(1)
        ->and($messagesForThread->getCollection()->first())
        ->toBeInstanceOf(Message::class)
        ->id->toBe($message->getKey())
        ->message->toBe('What!');
});
