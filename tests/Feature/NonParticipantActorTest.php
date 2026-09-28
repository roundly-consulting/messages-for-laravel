<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Roles decide what a participant may do; they never make an outsider a participant. With
 * roles switched off — and in a direct thread, which is always roleless — every participant
 * may manage the thread, but someone who is not in it may not touch it at all.
 */
dataset('unguarded threads', [
    'group thread, roles off' => function (): array {
        config()->set('messages.permissions.enabled', false);
        $a = User::create();
        $b = User::create();

        return [Messages::start('Crew')->withParticipants([$a, $b])->create(), $a, $b];
    },
    'direct thread' => function (): array {
        config()->set('messages.permissions.enabled', true);
        $a = User::create();
        $b = User::create();

        return [Messages::direct($a, $b), $a, $b];
    },
]);

it('refuses every managing operation by a non-participant', function (Closure $setup, string $operation): void {
    /** @var Thread $thread */
    [$thread, $a, $b] = $setup();
    $stranger = User::create();
    $message = Messages::send($thread, $a, 'hi');
    $participants = Messages::thread($thread)->participants();

    $attempt = match ($operation) {
        'rename' => fn () => Messages::thread($thread)->rename('Taken', by: $stranger),
        'archive' => fn () => Messages::thread($thread)->archive(by: $stranger),
        'add' => fn () => $participants->add(User::create(), by: $stranger),
        'remove' => fn () => $participants->remove($b, by: $stranger),
        'setRole' => fn () => $participants->setRole($b, ParticipantRole::Admin, by: $stranger),
        'transferOwnership' => fn () => $participants->transferOwnership(from: $stranger, to: $b),
        'delete' => fn () => Messages::message($message)->delete(by: $stranger),
        'edit' => fn () => Messages::message($message)->edit('mine now', by: $stranger),
    };

    expect($attempt)->toThrow(UnauthorizedMessagingAction::class);

    $fresh = $thread->fresh();

    expect($fresh->name)->toBe($thread->name)
        ->and($fresh->isArchived())->toBeFalse()
        ->and($fresh->participants()->count())->toBe(2)
        ->and(Message::query()->find($message->getKey())?->message)->toBe('hi');
})->with('unguarded threads')->with([
    'rename', 'archive', 'add', 'remove', 'setRole', 'transferOwnership', 'delete', 'edit',
]);

it('still lets any participant manage an unguarded thread', function (Closure $setup): void {
    [$thread, $a, $b] = $setup();
    $message = Messages::send($thread, $a, 'hi');

    Messages::thread($thread)->rename('Ours', by: $b);
    Messages::message($message)->delete(by: $b);

    expect($thread->fresh()->name)->toBe('Ours')
        ->and($message->fresh()->trashed())->toBeTrue();
})->with('unguarded threads');

it('refuses an author who has left the thread their own message', function (string $operation): void {
    config()->set('messages.permissions.enabled', false);
    $a = User::create();
    $b = User::create();
    $thread = Messages::start('Crew')->withParticipants([$a, $b])->create();
    $message = Messages::send($thread, $b, 'before I left');
    Messages::thread($thread)->participants()->leave($b);

    match ($operation) {
        'edit' => Messages::message($message)->edit('after', by: $b),
        'delete' => Messages::message($message)->delete(by: $b),
    };
})->with(['edit', 'delete'])->throws(UnauthorizedMessagingAction::class);

it('refuses an actor deleting a message whose thread is gone', function (): void {
    $a = User::create();
    $thread = Messages::start('Crew')->withParticipant($a)->create();
    $message = Messages::send($thread, $a, 'hi');
    $thread->delete();

    Messages::message($message->fresh())->delete(by: $a);
})->throws(UnauthorizedMessagingAction::class);
