<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\MessagesManager;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

it('sets the parent on a reply and exposes the relation', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    $parent = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'original',
    ));

    $reply = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'reply',
        parentMessageId: (string) $parent->getKey(),
    ));

    expect($reply->parent_message_id)->toBe((string) $parent->getKey())
        ->and($reply->parent->getKey())->toBe($parent->getKey())
        ->and($parent->replies()->count())->toBe(1);
});

it('builds a reply through the pending message builder', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    $parent = Messages::send($thread, $user, 'first');

    $reply = app(MessagesManager::class)
        ->to($thread)
        ->from($user)
        ->replyingTo($parent)
        ->send('answer');

    expect($reply)->toBeReplyTo($parent);
});

it('stores a quote snapshot that survives the parent being deleted', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    $parent = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'quote me',
    ));

    $reply = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'reply',
        parentMessageId: (string) $parent->getKey(),
    ));

    expect($reply->meta['quote']['excerpt'])->toBe('quote me');

    $parent->delete();

    $reply->refresh();
    expect($reply->meta['quote']['excerpt'])->toBe('quote me');
});

it('rejects a reply to a message in another thread', function () {
    $user = User::create();
    $threadA = Messages::start('A')->withParticipant($user)->create();
    $threadB = Messages::start('B')->withParticipant($user)->create();

    $parent = app(SendMessage::class)->execute(new SendMessageData(
        thread: $threadA,
        sender: $user,
        body: 'in A',
    ));

    app(SendMessage::class)->execute(new SendMessageData(
        thread: $threadB,
        sender: $user,
        body: 'reply',
        parentMessageId: (string) $parent->getKey(),
    ));
})->throws(MessageException::class);

/**
 * The absent id must be well-typed for the configured key type (bigint by default). A random
 * uuid here is not "a missing message" but a type error: SQLite swallowed it by affinity and
 * returned no row, while Postgres rejects the lookup outright with a QueryException — which
 * is a different failure than the one this test means to pin. What it proves is that a
 * *valid but absent* parent is refused by the package, not by the driver.
 */
it('rejects a reply to a missing parent message', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'reply',
        parentMessageId: 999_999_999,
    ));
})->throws(MessageException::class);

it('builds the cross-thread reply exception with a translated message', function () {
    expect(MessageException::replyAcrossThreads()->getMessage())
        ->toBe('A reply must target a message in the same thread.');
});

it('resolves the message model for parent and replies relations', function () {
    expect((new Message)->parent())->toBeInstanceOf(BelongsTo::class)
        ->and((new Message)->replies())->toBeInstanceOf(HasMany::class);
});
