<?php

declare(strict_types=1);

use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\Messages\Tests\Models\Person;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Join and leave notices store the participant as its `participateAs()` snapshot — an array —
 * so they must read as the participant's `name`, never as the bare `:participant` placeholder.
 */
beforeEach(function (): void {
    config()->set('messages.system-messages.enabled', true);
});

afterEach(function (): void {
    app()->setLocale('en');
});

/** @return list<string> */
function systemPreviews(Thread $thread): array
{
    return $thread->messages()
        ->where('type', MessageType::System->value)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $message): string => $message->preview())
        ->all();
}

function systemRow(Thread $thread, string $key, array $meta): Message
{
    /** @var Message */
    return Message::query()->create([
        'thread_id' => $thread->getKey(),
        'message' => $key,
        'type' => MessageType::System,
        'meta' => $meta,
    ]);
}

it('names the participant in join and leave notices', function (string $locale, array $expected): void {
    $owner = Person::create(['name' => 'Olivia']);
    $alice = Person::create(['name' => 'Alice']);
    $thread = Messages::start('Crew')->withParticipant($owner)->create();

    Messages::thread($thread)->participants()->add($alice, by: $owner);
    Messages::thread($thread)->participants()->leave($alice);

    app()->setLocale($locale);

    expect(systemPreviews($thread))->toBe($expected);
})->with([
    'en' => ['en', [
        'Olivia joined the conversation.',
        'Alice joined the conversation.',
        'Alice left the conversation.',
    ]],
    'sk' => ['sk', [
        'Účastník Olivia sa pripojil ku konverzácii.',
        'Účastník Alice sa pripojil ku konverzácii.',
        'Účastník Alice opustil konverzáciu.',
    ]],
]);

it('falls back to an unnamed participant when there is no name', function (string $locale, array $expected): void {
    $owner = User::create();
    $nameless = Person::create(['name' => '  ']);
    $thread = Messages::start('Crew')->withParticipant($owner)->create();

    Messages::thread($thread)->participants()->add($nameless, by: $owner);

    app()->setLocale($locale);

    expect(systemPreviews($thread))->toBe($expected);
})->with([
    'en' => ['en', [
        'An unnamed participant joined the conversation.',
        'An unnamed participant joined the conversation.',
    ]],
    'sk' => ['sk', [
        'Účastník bez mena sa pripojil ku konverzácii.',
        'Účastník bez mena sa pripojil ku konverzácii.',
    ]],
]);

it('renders notices stored before the fix', function (array $meta, string $expected): void {
    $thread = Messages::start('Crew')->create();

    $row = systemRow($thread, 'messages::messages.system.participant_left', $meta);

    expect($row->fresh()?->preview())->toBe($expected);
})->with([
    'a named snapshot' => [['participant' => ['id' => 7, 'name' => 'Bob']], 'Bob left the conversation.'],
    'an id-only snapshot' => [['participant' => ['id' => 7]], 'An unnamed participant left the conversation.'],
    'a non-participating model' => [['participant' => []], 'An unnamed participant left the conversation.'],
    'a scalar participant' => [['participant' => 'Carol'], 'Carol left the conversation.'],
]);

it('still fills scalar replacements of other system messages', function (): void {
    $thread = Messages::start('Crew')->create();

    $row = systemRow($thread, 'messages::messages.system.thread_renamed', ['name' => 'Launch']);

    expect($row->fresh()?->preview())->toBe('The conversation was renamed to Launch.');
});
