<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Http\Resources\MessageParticipantResource;
use RoundlyConsulting\Messages\Http\Resources\MessageResource;
use RoundlyConsulting\Messages\Http\Resources\ParticipantResource;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Support\MessagesConfig;
use RoundlyConsulting\Messages\Tests\Fixtures\AbstractParticipantResource;
use RoundlyConsulting\Messages\Tests\Fixtures\HostParticipantResource;
use RoundlyConsulting\Messages\Tests\Models\Restaurant;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(function (): void {
    config()->set('messages.permissions.enabled', false);
});

it('renders a participating model as its participateAs() snapshot', function (): void {
    $user = User::create();

    expect(MessageParticipantResource::make($user)->resolve(Request::create('/')))->toBe(['id' => $user->id]);
});

it('renders any other model as its key and class basename', function (): void {
    $restaurant = Restaurant::create();

    expect(MessageParticipantResource::make($restaurant)->resolve(Request::create('/')))->toBe([
        'id' => $restaurant->getKey(),
        'type' => 'Restaurant',
    ]);
});

it('builds the configured resource around a model, and none around no model', function (): void {
    $user = User::create();

    expect(MessageParticipantResource::configured($user))->toBeInstanceOf(MessageParticipantResource::class)
        ->and(MessageParticipantResource::configured(null))->toBeNull();

    config()->set('messages.resources.participant', HostParticipantResource::class);

    expect(MessageParticipantResource::configured($user))->toBeInstanceOf(HostParticipantResource::class);
});

it('keeps the participateAs() shape of a participating participant', function (): void {
    $user = User::create();
    $thread = Messages::start('Crew')->withParticipant($user)->create();

    $participant = $thread->participants()->with('participant')->firstOrFail();

    expect(ParticipantResource::make($participant)->resolve(Request::create('/'))['participant'])->toBe(['id' => $user->id]);
});

it('renders a sender that does not take part in messaging by key and class basename', function (): void {
    $thread = Messages::start('Crew')->create();
    $restaurant = Restaurant::create();

    $message = app(SendMessage::class)->execute(new SendMessageData(
        thread: $thread,
        sender: $restaurant,
        body: 'Your table is ready.',
        requireParticipation: false,
    ));
    $message->load('sender');

    expect(MessageResource::make($message)->resolve(Request::create('/'))['sender'])->toBe([
        'id' => $restaurant->getKey(),
        'type' => 'Restaurant',
    ]);
});

it('renders a participant and a sender through the configured resource', function (): void {
    config()->set('messages.resources.participant', HostParticipantResource::class);

    $user = User::create();
    $thread = Messages::start('Crew')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'hello');
    $message->load('sender');
    $participant = $thread->participants()->with('participant')->firstOrFail();

    $request = Request::create('/');

    expect(ParticipantResource::make($participant)->resolve($request)['participant'])->toBe(['key' => $user->id, 'kind' => 'host'])
        ->and(MessageResource::make($message)->resolve($request)['sender'])->toBe(['key' => $user->id, 'kind' => 'host']);
});

it('renders null for a participant whose model no longer exists', function (): void {
    $thread = Messages::start('Crew')->create();
    $restaurant = Restaurant::create();
    $thread->participants()->create([
        'participant_id' => $restaurant->getKey(),
        'participant_type' => $restaurant->getMorphClass(),
    ]);
    $restaurant->delete();

    $participant = $thread->participants()->with('participant')->firstOrFail();
    $resolved = ParticipantResource::make($participant)->resolve(Request::create('/'));

    expect($resolved)->toHaveKey('participant')
        ->and($resolved['participant'])->toBeNull();
});

it('renders null for the sender of a system message', function (): void {
    $thread = Messages::start('Crew')->create();
    $message = Message::factory()->inThread($thread)->system()->create()->load('sender');

    $resolved = MessageResource::make($message)->resolve(Request::create('/'));

    expect($resolved)->toHaveKey('sender')
        ->and($resolved['sender'])->toBeNull();
});

it('omits the participant and the sender until their relations are loaded', function (): void {
    $user = User::create();
    $thread = Messages::start('Crew')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'hello')->fresh();
    $participant = $thread->participants()->firstOrFail();

    $request = Request::create('/');

    expect(ParticipantResource::make($participant)->resolve($request))->not->toHaveKey('participant')
        ->and(MessageResource::make($message)->resolve($request))->not->toHaveKey('sender');
});

it('reads a blank participant resource as the default (strict config)', function (mixed $value): void {
    config()->set('messages.resources.participant', $value);

    expect(MessagesConfig::participantResource())->toBe(MessageParticipantResource::class);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['  '],
]);

it('refuses a participant resource that is not a JsonResource (strict config)', function (mixed $value): void {
    config()->set('messages.resources.participant', $value);

    $user = User::create();
    $thread = Messages::start('Crew')->withParticipant($user)->create();
    $participant = $thread->participants()->with('participant')->firstOrFail();

    expect(fn (): array => ParticipantResource::make($participant)->resolve(Request::create('/')))
        ->toThrow(InvalidConfigurationException::class, 'messages.resources.participant');
})->with([
    'missing class' => ['App\\Http\\Resources\\Missing'],
    'not a resource' => [User::class],
    'the bare base class' => [JsonResource::class],
    'an abstract resource' => [AbstractParticipantResource::class],
    'not a string' => [[MessageParticipantResource::class]],
]);
