<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\DataTransferObjects\AddParticipantData;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\DataTransferObjects\MarkReadData;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
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
    $thread = messaging()->threads()->create(name: 'X');
    $sender = User::create();

    $data = new SendMessageData(thread: $thread, sender: $sender, body: 'hi');

    expect($data->type)->toBe(MessageType::Text)
        ->and($data->meta)->toBe([])
        ->and($data->body)->toBe('hi');
});

it('builds the remaining write DTOs', function () {
    $thread = messaging()->threads()->create(name: 'X');
    $user = User::create();
    $message = Message::factory()->inThread($thread)->create();

    expect((new AddParticipantData($thread, $user))->thread)->toBe($thread)
        ->and((new MarkReadData($thread, $user))->participant)->toBe($user)
        ->and((new EditMessageData($message, 'edited'))->body)->toBe('edited')
        ->and((new PruneMessagesData(days: 30, threadId: $thread->getKey()))->days)->toBe(30);
});
