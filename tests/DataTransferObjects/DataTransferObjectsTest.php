<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\DataTransferObjects\MessagingCall;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\DataTransferObjects\RemoveParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Enums\MessagingOperation;
use RoundlyConsulting\Messages\Enums\ParticipantRole;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('builds CreateThreadData with sensible defaults', function () {
    $data = new CreateThreadData(name: 'Team');

    expect($data->name)->toBe('Team')
        ->and($data->isPublic)->toBeNull()
        ->and($data->isDirect)->toBeFalse()
        ->and($data->participants)->toBe([]);
});

it('builds SendMessageData defaulting to a text message', function () {
    $thread = Messages::start('X')->create();
    $sender = User::create();

    $data = new SendMessageData(thread: $thread, sender: $sender, body: 'hi');

    expect($data->type)->toBe(MessageType::Text)
        ->and($data->meta)->toBe([])
        ->and($data->body)->toBe('hi');
});

it('builds the remaining write DTOs', function () {
    $thread = Messages::start('X')->create();
    $user = User::create();
    $message = Message::factory()->inThread($thread)->create();

    expect((new AddParticipantData($thread, $user))->thread)->toBe($thread)
        ->and((new MarkReadData($thread, $user))->participant)->toBe($user)
        ->and((new EditMessageData($message, 'edited'))->body)->toBe('edited')
        ->and((new EditMessageData($message, 'edited'))->actor)->toBeNull()
        ->and((new EditMessageData($message, 'edited', $user))->actor)->toBe($user)
        ->and((new RemoveParticipantData($thread, $user))->actor)->toBeNull()
        ->and((new RemoveParticipantData($thread, $user, $user))->participant)->toBe($user)
        ->and((new PruneMessagesData(days: 30, threadId: $thread->getKey()))->days)->toBe(30);
});

it('copies every field of a MessagingCall when attaching its result', function () {
    $thread = Messages::start('X')->create();
    $user = User::create();
    $message = Message::factory()->inThread($thread)->create();

    $call = new MessagingCall(
        operation: MessagingOperation::SetRole,
        thread: $thread,
        message: $message,
        participant: $user,
        actor: $user,
        text: 'text',
        role: ParticipantRole::Admin,
        days: 7,
    );

    $withResult = $call->withResult('done');

    expect($call->result)->toBeNull()
        ->and($withResult)->not->toBe($call)
        ->and($withResult->result)->toBe('done')
        ->and($withResult->operation)->toBe(MessagingOperation::SetRole)
        ->and($withResult->thread)->toBe($thread)
        ->and($withResult->message)->toBe($message)
        ->and($withResult->participant)->toBe($user)
        ->and($withResult->actor)->toBe($user)
        ->and($withResult->text)->toBe('text')
        ->and($withResult->role)->toBe(ParticipantRole::Admin)
        ->and($withResult->days)->toBe(7);
});
