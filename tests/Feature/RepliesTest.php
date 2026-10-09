<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
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

/**
 * A connection with `PDO::ATTR_STRINGIFY_FETCHES` on (a `database.connections.*.options`
 * setting) hands every column back as a string, while the key Eloquent casts stays an int. A
 * strict comparison of the two refused a reply to a message of the very same thread.
 */
it('accepts a same-thread reply on a connection that fetches strings', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $parent = Messages::send($thread, $user, 'original');

    $pdo = DB::connection()->getPdo();
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);

    try {
        $reply = app(SendMessage::class)->execute(new SendMessageData(
            thread: $thread,
            sender: $user,
            body: 'reply',
            parentMessageId: $parent->getKey(),
        ));
    } finally {
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    expect($reply->meta['quote']['excerpt'])->toBe('original');
});

/**
 * A system message stores a translation key as its body; the quote snapshot copied that key
 * raw, so the reply quoted `messages::messages.system.participant_joined`. It quotes what the
 * thread shows for that message — its preview.
 */
it('quotes a system message as it reads, not as its translation key', function () {
    config()->set('messages.system-messages.enabled', true);
    $alice = User::create();
    $bob = User::create();
    $thread = Messages::start('Chat')->withParticipant($alice)->create();
    Messages::thread($thread)->participants()->add($bob);

    $joined = Message::query()->where('type', MessageType::System->value)->latest('id')->firstOrFail();

    $reply = Messages::to($thread)->from($alice)->replyingTo($joined)->send('welcome!');

    expect($reply->meta['quote']['excerpt'])->toBe($joined->preview())
        ->not->toContain('messages::')
        ->and(MessageResource::make($reply)->toArray(Request::create('/'))['reply_to']['excerpt'])->toBe($joined->preview());
});
