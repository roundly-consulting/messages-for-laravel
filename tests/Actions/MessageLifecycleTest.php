<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\Actions\EditMessage;
use RoundlyConsulting\Messages\DataTransferObjects\EditMessageData;
use RoundlyConsulting\Messages\Events\MessageDeleted;
use RoundlyConsulting\Messages\Events\MessageEdited;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    $this->thread = Messages::start('Chat')->create();
    $this->author = User::create();
    $this->message = Messages::send($this->thread, $this->author, 'first');
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

it('lets the author edit their own message', function () {
    $updated = app(EditMessage::class)->execute(new EditMessageData($this->message, 'mine', $this->author));

    expect($updated->message)->toBe('mine');
});

it('refuses an edit by anyone but the author, even with roles switched off', function () {
    config()->set('messages.permissions.enabled', false);

    app(EditMessage::class)->execute(new EditMessageData($this->message, 'hijacked', User::create()));
})->throws(UnauthorizedMessagingAction::class);

it('refuses an actor edit of a system message, which has no author', function () {
    $system = Messages::send($this->thread, null, 'system note');

    app(EditMessage::class)->execute(new EditMessageData($system, 'nope', $this->author));
})->throws(UnauthorizedMessagingAction::class);
