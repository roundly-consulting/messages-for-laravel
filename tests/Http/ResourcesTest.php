<?php

declare(strict_types=1);

use App\Http\Resources\Messages\MessageResource as PublishedMessageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Http\Resources\ParticipantResource;
use RoundlyConsulting\Messages\Http\Resources\ThreadResource;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function () {
    config()->set('messages.permissions.enabled', false);
});

it('shapes a thread resource with eager-loaded relations', function () {
    $reader = User::create();
    $sender = User::create();
    $reader->startConversationWith($sender, 'Crew');

    app(SendMessage::class)->execute(new SendMessageData(
        thread: $reader->threads()->first(),
        sender: $sender,
        body: 'hi',
    ));

    $thread = Messages::inboxFor($reader)->items()[0];

    $payload = ThreadResource::make($thread)->toArray(Request::create('/'));

    expect($payload)
        ->id->toBe($thread->id)
        ->name->toBe('Crew')
        ->is_direct->toBeFalse()
        ->unread_count->toBe(1)
        ->and($payload['latest_message'])->not->toBeNull()
        ->and($payload['participants'])->toHaveCount(2);
});

it('omits unloaded thread relations safely', function () {
    $thread = Messages::start('Bare')->create();

    $payload = ThreadResource::make($thread)->toArray(Request::create('/'));

    expect($payload)->toHaveKey('id');
    // Unloaded scope attribute is dropped (MissingValue), so it never leaks a stale count.
    expect($payload['unread_count'])->toBeInstanceOf(MissingValue::class);

    // Resolving the resource for a response filters the unloaded relations out entirely.
    $resolved = ThreadResource::make($thread)->resolve(Request::create('/'));
    expect($resolved)
        ->not->toHaveKey('unread_count')
        ->not->toHaveKey('latest_message')
        ->not->toHaveKey('participants');
});

it('shapes a message resource including the sender and reply info', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    $parent = app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'original'));
    $reply = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $user,
        body: 'reply',
        parentMessageId: (string) $parent->getKey(),
    ));

    $reply->load('sender');

    $payload = MessageResource::make($reply)->toArray(Request::create('/'));

    expect($payload)
        ->id->toBe($reply->id)
        ->body->toBe('reply')
        ->type->toBe('text')
        ->is_deleted->toBeFalse()
        ->and($payload['sender'])->toBe(['id' => $user->id])
        ->and($payload['reply_to']['id'])->toBe((string) $parent->getKey())
        ->and($payload['reply_to']['excerpt'])->toBe('original');
});

it('nulls the body for a deleted message resource', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'bye'));
    $message->delete();

    $payload = MessageResource::make($message->fresh())->toArray(Request::create('/'));

    expect($payload)
        ->body->toBeNull()
        ->is_deleted->toBeTrue()
        ->reply_to->toBeNull();
});

it('reports an edited timestamp once a text message changes', function () {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'first'));

    expect(MessageResource::make($message)->toArray(Request::create('/'))['edited_at'])->toBeNull();

    $this->travel(1)->minutes();
    $message->forceFill(['message' => 'second'])->save();

    expect(MessageResource::make($message->fresh())->toArray(Request::create('/'))['edited_at'])->not->toBeNull();

    $this->travelBack();
});

it('shapes a participant resource', function () {
    $reader = User::create();
    $sender = User::create();
    $reader->startConversationWith($sender, 'Crew');

    $participant = $reader->threads()->first()->participants()->with('participant')->first();

    $payload = ParticipantResource::make($participant)->toArray(Request::create('/'));

    expect($payload)
        ->id->toBe($participant->id)
        ->and($payload['participant'])->toBe(['id' => $reader->id]);
});

it('falls back to ids when the participant model does not participate', function () {
    $thread = Messages::start('Crew')->create();
    $restaurant = Restaurant::create();
    $thread->participants()->create([
        'participant_id' => $restaurant->getKey(),
        'participant_type' => $restaurant->getMorphClass(),
    ]);

    $participant = $thread->participants()->with('participant')->first();

    $payload = ParticipantResource::make($participant)->toArray(Request::create('/'));

    expect($payload['participant'])
        ->toHaveKey('id')
        ->toHaveKey('type');
});

/**
 * `edited_at` was read off `updated_at`, which a soft delete and a restore stamp too: an unsent
 * message reported an edit, and so did one restored afterwards, though neither was ever
 * reworded — while a real edit in the same second as the send reported none. An edit is now
 * recorded where it happens, and both the package resource and the published one read that.
 */
it('reports no edit for a message unsent and restored', function () {
    require_once dirname(__DIR__, 2).'/stubs/Http/Resources/MessageResource.php.stub';
    $this->freezeSecond();

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'never reworded');

    $this->travel(5)->minutes();
    Messages::message($message)->delete();
    $unsent = $message->fresh();

    $this->travel(1)->minute();
    $message->restore();
    $restored = $message->fresh();

    $request = Request::create('/');

    expect(MessageResource::make($unsent)->toArray($request)['edited_at'])->toBeNull()
        ->and(MessageResource::make($restored)->toArray($request)['edited_at'])->toBeNull()
        ->and(PublishedMessageResource::make($unsent)->toArray($request)['edited_at'])->toBeNull()
        ->and(PublishedMessageResource::make($restored)->toArray($request)['edited_at'])->toBeNull();
});

it('reports an edit made in the same second as the send', function () {
    require_once dirname(__DIR__, 2).'/stubs/Http/Resources/MessageResource.php.stub';
    $this->freezeSecond();

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'tpyo');
    Messages::message($message)->edit('typo', by: $user);

    $request = Request::create('/');
    $editedAt = MessageResource::make($message->fresh())->toArray($request)['edited_at'];

    expect($editedAt)->toBe(now()->toIso8601String())
        ->and(PublishedMessageResource::make($message->fresh())->toArray($request)['edited_at'])->toBe($editedAt);
});
