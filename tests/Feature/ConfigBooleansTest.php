<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Messages\Actions\SignalTyping;
use RoundlyConsulting\Messages\Actions\StartThread;
use RoundlyConsulting\Messages\DataTransferObjects\CreateThreadData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Listeners\WarmMessageMediaVariants;
use RoundlyConsulting\Messages\Notifications\NewMessageNotification;
use RoundlyConsulting\Messages\Tests\Models\NotifiableUser;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Every switch is read as a boolean. The shipped config feeds most of them from env(), and
 * env() only converts 'true'/'false' — `MESSAGES_PERMISSIONS=1` arrives as the STRING '1'. A
 * strict `=== true` read that as OFF (role enforcement silently disabled), and a `(bool)` cast
 * read `THREADS_PUBLIC=off` as ON (new threads silently public).
 */
function messagesAbout(): string
{
    Artisan::call('about', ['--only' => 'messages']);

    return Artisan::output();
}

dataset('truthy strings', ['1', 'on', 'yes']);
dataset('falsy strings', ['0', 'off', 'no']);

it('enforces roles when the switch is a truthy string', function (string $value): void {
    config()->set('messages.permissions.enabled', $value);

    $owner = User::create();
    $member = User::create();
    $thread = app(StartThread::class)->execute(new CreateThreadData(name: 'Crew', participants: [$owner, $member]));

    expect(messagesAbout())->toMatch('/Roles\s*\.*\s*ENFORCED/')
        ->and(fn () => Messages::thread($thread)->rename('Mine now', by: $member))
        ->toThrow(UnauthorizedMessagingAction::class);
})->with('truthy strings');

it('stops enforcing roles when the switch is a falsy string', function (string $value): void {
    config()->set('messages.permissions.enabled', $value);

    $owner = User::create();
    $member = User::create();
    $thread = app(StartThread::class)->execute(new CreateThreadData(name: 'Crew', participants: [$owner, $member]));

    Messages::thread($thread)->rename('Ours', by: $member);

    expect($thread->refresh()->name)->toBe('Ours')
        ->and(messagesAbout())->toMatch('/Roles\s*\.*\s*OFF/');
})->with('falsy strings');

it('broadcasts when the switch is a truthy string', function (string $value): void {
    config()->set('messages.broadcasting.enabled', $value);
    Event::fake([ParticipantTyping::class]);

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();
    $message = Messages::send($thread, $user, 'Yay!');

    app(SignalTyping::class)->execute($thread, $user);

    Event::assertDispatched(ParticipantTyping::class);
    expect($message->broadcastOn('created'))->toBeInstanceOf(PrivateChannel::class)
        ->and((new ParticipantTyping($thread, $user))->broadcastOn())->toHaveCount(1)
        ->and($thread->participants()->first()?->broadcastOn('created'))->toBeInstanceOf(PrivateChannel::class)
        ->and($thread->broadcastOn('created'))->not->toBe([])
        ->and(messagesAbout())->toMatch('/Broadcasting\s*\.*\s*ON/');
})->with('truthy strings');

it('writes system messages when the switch is a truthy string', function (string $value): void {
    config()->set('messages.system-messages.enabled', $value);
    config()->set('messages.permissions.enabled', false);

    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($owner = User::create())->create();
    Messages::thread($thread)->participants()->add($user);
    Messages::thread($thread)->rename('Renamed', by: $owner);
    Messages::thread($thread)->participants()->remove($user);

    // owner joins, member joins, rename, member leaves.
    expect($thread->messages()->where('type', MessageType::System->value)->count())->toBe(4)
        ->and(messagesAbout())->toMatch('/System messages\s*\.*\s*ON/');
})->with('truthy strings');

it('notifies participants when the switch is a truthy string', function (string $value): void {
    config()->set('messages.notifications.enabled', $value);
    config()->set('messages.permissions.enabled', false);
    Notification::fake();

    $sender = NotifiableUser::create();
    $recipient = NotifiableUser::create();
    $sender->sendMessageTo($sender->startConversationWith($recipient, 'Crew'), 'hello');

    Notification::assertSentTo($recipient, NewMessageNotification::class);
    expect(messagesAbout())->toMatch('/Notifications\s*\.*\s*ON/');
})->with('truthy strings');

it('keeps new threads private and invite-only when the defaults are falsy strings', function (string $value): void {
    config()->set('messages.publicity.public-by-default', $value);
    config()->set('messages.publicity.everyone-can-join', $value);

    $thread = app(StartThread::class)->execute(new CreateThreadData(name: 'Quiet'));

    expect($thread->is_public)->toBeFalse()
        ->and($thread->everyone_can_join)->toBeFalse()
        ->and(messagesAbout())->toMatch('/New threads\s*\.*\s*PRIVATE \(invite only\)/');
})->with('falsy strings');

it('opens new threads when the defaults are truthy strings', function (string $value): void {
    config()->set('messages.publicity.public-by-default', $value);
    config()->set('messages.publicity.everyone-can-join', $value);

    $thread = app(StartThread::class)->execute(new CreateThreadData(name: 'Open'));

    expect($thread->is_public)->toBeTrue()
        ->and($thread->everyone_can_join)->toBeTrue()
        ->and(messagesAbout())->toMatch('/New threads\s*\.*\s*PUBLIC \(anyone may join\)/');
})->with('truthy strings');

it('skips warming variants when the switch is a falsy string', function (string $value): void {
    config()->set('messages.media.warm_on_send', $value);
    Storage::fake('public');
    Storage::fake('local');
    Queue::fake();

    $thread = Messages::start('Chat')->withParticipant($sender = User::create())->create();
    $message = Messages::to($thread)->from($sender)->send('hi');
    $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');

    (new WarmMessageMediaVariants)->handle(new MessageSent($message));

    Queue::assertNotPushed(GenerateVariantsJob::class);
    expect(messagesAbout())->toMatch('/Warm variants on send\s*\.*\s*OFF/');
})->with('falsy strings');

it('keeps attachments on force delete when cleanup is a falsy string', function (string $value): void {
    config()->set('messages.media.cleanup_on_force_delete', $value);
    Storage::fake('public');
    Storage::fake('local');

    $thread = Messages::start('Chat')->withParticipant($sender = User::create())->create();
    $message = Messages::to($thread)->from($sender)->send('hi');
    $media = $message->addMedia(UploadedFile::fake()->image('keep.jpg', 400, 300))->toMediaBucket('attachments');

    $message->forceDelete();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(messagesAbout())->toMatch('/Attachment cleanup\s*\.*\s*OFF/');
})->with('falsy strings');

it('throws on a switch typo instead of reading it as the default (strict config)', function (string $key, Closure $read): void {
    config()->set($key, 'disabled');

    expect($read)->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [{$key}] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.",
    );
})->with([
    'publicity.public-by-default' => ['messages.publicity.public-by-default', fn () => app(StartThread::class)->execute(new CreateThreadData(name: 'Crew', participants: [User::create(), User::create()]))],
    'permissions.enabled' => ['messages.permissions.enabled', fn (): string => messagesAbout()],
    'system-messages.enabled' => ['messages.system-messages.enabled', fn (): string => messagesAbout()],
    'broadcasting.enabled' => ['messages.broadcasting.enabled', fn (): string => messagesAbout()],
    'media.warm_on_send' => ['messages.media.warm_on_send', fn (): string => messagesAbout()],
]);

it('throws on a key type typo instead of reading it as bigint (strict config)', function (): void {
    config()->set('messages.primary_key_type', 'uiid');

    expect(fn (): string => messagesAbout())
        ->toThrow(InvalidConfigurationException::class, 'messages.primary_key_type');
});
