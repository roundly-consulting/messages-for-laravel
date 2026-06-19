<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\Actions\EditMessage;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Events\MessageEdited;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->thread = messaging()->threads()->create(name: 'Chat');
    $this->message = messaging()->messages()->sendMessage($this->thread, User::create(), 'first');
});

it('edits a message and dispatches MessageEdited', function () {
    Event::fake();

    $updated = app(EditMessage::class)->execute(new EditMessageData($this->message, 'edited'));

    expect($updated->message)->toBe('edited');

    Event::assertDispatched(MessageEdited::class);
});

it('soft deletes a message and dispatches MessageDeleted', function () {
    Event::fake();

    app(DeleteMessage::class)->execute($this->message);

    expect(Message::query()->withTrashed()->find($this->message->getKey())->trashed())->toBeTrue();

    Event::assertDispatched(MessageDeleted::class);
});

it('throws when editing a deleted message', function () {
    app(DeleteMessage::class)->execute($this->message);

    app(EditMessage::class)->execute(new EditMessageData($this->message, 'nope'));
})->throws(MessageException::class);

it('throws when deleting an already deleted message', function () {
    app(DeleteMessage::class)->execute($this->message);

    app(DeleteMessage::class)->execute($this->message);
})->throws(MessageException::class);
