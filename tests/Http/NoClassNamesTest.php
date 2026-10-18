<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Http\Resources\ParticipantResource;
use RoundlyConsulting\Messages\Http\Resources\ThreadResource;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\Messages\Tests\Models\NotifiableUser;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;

/*
 * No class names over the wire (owner rule, 2026-10-18). Without a morph map a morph type IS the
 * class name (`App\Models\User`): the participant fallback rendered it as `participant.type`, a
 * reply's `reply_to.sender_type` and the new-message notification's `sender_type` carried it to
 * every client. Each now names the model by its class basename.
 */

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', false);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

/** What a client receives: the resource as the HTTP response body. */
function noClassNamesWireJson(JsonResource $resource): string
{
    return (string) $resource->toResponse(Request::create('/'))->getContent();
}

/** A JSON-encoded backslash — any class name in a payload value shows up as one. */
const NO_CLASS_NAMES_ENCODED_BACKSLASH = '\\\\';

it('renders a participant that does not take part in messaging by key and class basename', function (): void {
    $thread = Messages::start('Crew')->create();
    $restaurant = Restaurant::create();
    $thread->participants()->create([
        'participant_id' => $restaurant->getKey(),
        'participant_type' => $restaurant->getMorphClass(),
    ]);

    $participant = $thread->participants()->with('participant')->firstOrFail();
    $resource = ParticipantResource::make($participant);

    expect($resource->resolve(Request::create('/'))['participant'])->toBe([
        'id' => $restaurant->getKey(),
        'type' => 'Restaurant',
    ])
        ->and(noClassNamesWireJson($resource))->not->toContain(NO_CLASS_NAMES_ENCODED_BACKSLASH);
});

it('keeps no class name anywhere in a thread payload with an outsider participant', function (): void {
    $user = User::create();
    $restaurant = Restaurant::create();
    $thread = Messages::start('Crew')->withParticipant($user)->create();
    $thread->participants()->create([
        'participant_id' => $restaurant->getKey(),
        'participant_type' => $restaurant->getMorphClass(),
    ]);
    $parent = Messages::send($thread, $user, 'Table for two?');
    Messages::to($thread)->from($user)->replyingTo($parent)->send('At eight.');

    $thread = Messages::inboxFor($user)->items()[0];

    expect(noClassNamesWireJson(ThreadResource::make($thread)))->not->toContain(NO_CLASS_NAMES_ENCODED_BACKSLASH)
        ->toContain('"type":"Restaurant"');
});

it('names the quoted sender of a reply by class basename', function (): void {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $parent = Messages::send($thread, $user, 'original');
    $reply = Messages::to($thread)->from($user)->replyingTo($parent)->send('reply');

    $resource = MessageResource::make($reply);

    expect($resource->resolve(Request::create('/'))['reply_to'])->toBe([
        'id' => $parent->getKey(),
        'excerpt' => 'original',
        'sender_id' => $user->getKey(),
        'sender_type' => 'User',
    ])
        ->and(noClassNamesWireJson($resource))->not->toContain(NO_CLASS_NAMES_ENCODED_BACKSLASH);
});

it('names the quoted sender by its class basename under a morph map, not the alias', function (): void {
    Relation::morphMap(['diner' => User::class]);

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $parent = Messages::send($thread, $user, 'original');
    $reply = Messages::to($thread)->from($user)->replyingTo($parent)->send('reply');

    expect($reply->meta['quote']['sender_type'])->toBe('diner')
        ->and(MessageResource::make($reply)->resolve(Request::create('/'))['reply_to']['sender_type'])->toBe('User');
});

it('names the new-message notification sender by class basename', function (): void {
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = app(SendMessage::class)->execute(new SendMessageData($thread, $user, 'payload'));

    $payload = (new NewMessageNotification($message))->toArray(NotifiableUser::create());

    expect($payload['sender_type'])->toBe('User')
        ->and($payload['sender_id'])->toBe($user->getKey())
        ->and((string) json_encode($payload))->not->toContain(NO_CLASS_NAMES_ENCODED_BACKSLASH);
});

it('names the notification sender by its class basename under a morph map', function (): void {
    Relation::morphMap(['diner' => User::class]);

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'payload');

    expect($message->sender_type)->toBe('diner')
        ->and((new NewMessageNotification($message))->toArray(NotifiableUser::create())['sender_type'])->toBe('User');
});

it('sends a null sender type for a system message', function (): void {
    $thread = Messages::start('Chat')->create();
    $message = Message::factory()->inThread($thread)->system()->create();

    expect((new NewMessageNotification($message))->toArray(NotifiableUser::create())['sender_type'])->toBeNull();
});
