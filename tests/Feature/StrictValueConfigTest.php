<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Messages\Events\ParticipantTyping;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\Messages\Tests\Models\NotifiableUser;
use RoundlyConsulting\Messages\Tests\Models\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Sweep 2 — the non-boolean settings. `(int) config()` read `MESSAGES_PRUNE_DAYS=ninety` as 0 —
 * a prune with no retention at all; an attachment visibility typo, a blank bucket or disk, a junk
 * lifetime and a null broadcast channel quietly fell back or were cast to `''`. Each now throws.
 */
beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

afterEach(function (): void {
    Env::getRepository()->clear('MESSAGES_PRUNE_DAYS');
    Env::getRepository()->clear('MESSAGES_PREVIEW_LENGTH');
});

function strictMessage(): Message
{
    $user = User::create();
    $thread = Messages::start('Chat')->withParticipant($user)->create();

    return Messages::to($thread)->from($user)->send('hello there');
}

it('hands the numeric env values to the reader raw (strict config)', function (): void {
    Env::getRepository()->set('MESSAGES_PRUNE_DAYS', 'ninety');
    Env::getRepository()->set('MESSAGES_PREVIEW_LENGTH', 'long');

    $config = require __DIR__.'/../../config/messages.php';

    expect($config['prune']['days'])->toBe('ninety')
        ->and($config['preview']['length'])->toBe('long');
});

it('refuses a junk or non-positive prune window instead of pruning everything (strict config)', function (mixed $value, string $message): void {
    $message = $message;
    config()->set('messages.prune.days', $value);
    strictMessage();

    expect(fn () => Messages::prune())->toThrow(InvalidConfigurationException::class, $message)
        ->and(Message::query()->count())->toBe(1);
})->with([
    'junk' => ['ninety', 'Configuration value [messages.prune.days] must be an integer, [ninety] given.'],
    'blank' => ['', "Configuration value [messages.prune.days] must be an integer, [''] given."],
    'zero' => [0, 'Configuration value [messages.prune.days] must be at least 1, [0] given.'],
]);

it('refuses a junk prune window in the command too (strict config)', function (): void {
    config()->set('messages.prune.days', 'ninety');
    strictMessage();

    expect(fn () => Artisan::call('messages:prune'))->toThrow(InvalidConfigurationException::class, 'messages.prune.days')
        ->and(Message::query()->count())->toBe(1);
});

it('reads an integer string prune window (strict config)', function (): void {
    config()->set('messages.prune.days', '30');
    $message = strictMessage();
    $message->forceFill(['created_at' => now()->subDays(31)])->save();

    expect(Messages::prune())->toBe(1);
});

it('refuses a junk or non-positive preview length (strict config)', function (mixed $value): void {
    $message = strictMessage();
    config()->set('messages.preview.length', $value);

    expect(fn () => $message->preview())->toThrow(InvalidConfigurationException::class, 'messages.preview.length');
})->with(['junk' => ['long'], 'zero' => [0], 'decimal' => ['12.5']]);

it('refuses an attachment visibility typo (strict config)', function (mixed $value): void {
    config()->set('messages.media.visibility', $value);

    expect(fn () => strictMessage()->resolveMediaBucket('attachments'))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [messages.media.visibility] must be one of [private, public]',
    );
})->with(['typo' => ['pubic'], 'capitalised' => ['Private'], 'blank' => ['']]);

it('keeps attachments private when the visibility is absent (strict config)', function (): void {
    config()->set('messages.media.visibility', null);

    expect(strictMessage()->resolveMediaBucket('attachments')?->getVisibility())->toBe('private');
});

it('refuses a blank or non-string media storage setting (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => strictMessage()->resolveMediaBucket('attachments'))
        ->toThrow(InvalidConfigurationException::class, "Configuration value [{$key}] must be a non-empty string");
})->with([
    'bucket blank' => ['messages.media.attachments_bucket', ''],
    'bucket array' => ['messages.media.attachments_bucket', ['a']],
    'disk blank' => ['messages.media.disk', ''],
    'private disk int' => ['messages.media.private_disk', 3],
]);

it('refuses a junk media list or size (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => strictMessage()->resolveMediaBucket('attachments'))
        ->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'mime types string' => ['messages.media.accepted_mime_types', 'image/png'],
    'mime types junk entry' => ['messages.media.accepted_mime_types', ['image/png', '']],
    'max file size junk' => ['messages.media.max_file_size', '2MB'],
    'max file size zero' => ['messages.media.max_file_size', 0],
    'widths string' => ['messages.media.responsive_widths', '120'],
    'widths junk entry' => ['messages.media.responsive_widths', [120, 0]],
]);

it('reads integer strings for the attachment size and widths (strict config)', function (): void {
    config()->set('messages.media.max_file_size', '4096');
    config()->set('messages.media.responsive_widths', ['120', 240]);

    $bucket = strictMessage()->resolveMediaBucket('attachments');

    expect($bucket?->getMaxFileSize())->toBe(4096)
        ->and($bucket?->getResponsiveWidths())->toBe([120, 240]);
});

it('refuses a junk signed-url lifetime (strict config)', function (string $key, mixed $value): void {
    $message = strictMessage();
    $media = $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');
    config()->set($key, $value);

    expect(fn () => $message->attachmentUrl($media))->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'message lifetime junk' => ['messages.media.temporary_url_lifetime', 'soon'],
    'message lifetime zero' => ['messages.media.temporary_url_lifetime', 0],
    'media default junk' => ['media.temporary_url_default_lifetime', 'soon'],
]);

it('refuses a blank or missing broadcast name instead of broadcasting on an empty one (strict config)', function (string $key, Closure $read): void {
    config()->set('messages.broadcasting.enabled', true);
    $message = strictMessage();

    foreach (['', ['x'], 5] as $value) {
        config()->set($key, $value);

        expect(fn () => $read($message))->toThrow(InvalidConfigurationException::class, $key);
    }
})->with([
    'messages channel' => ['messages.broadcasting.messages.channel', fn (Message $m) => $m->broadcastOn('created')],
    'messages created event' => ['messages.broadcasting.messages.events.created', fn (Message $m) => $m->broadcastAs('created')],
    'participants channel' => ['messages.broadcasting.participants.channel', fn (Message $m) => Participant::query()->firstOrFail()->broadcastOn('created')],
    'participants read event' => ['messages.broadcasting.participants.events.updated', fn (Message $m) => Participant::query()->firstOrFail()->broadcastAs('updated')],
    'typing event' => ['messages.broadcasting.typing.event', fn (Message $m) => (new ParticipantTyping($m->thread, User::create()))->broadcastAs()],
    'thread created event' => ['messages.broadcasting.threads.events.created', fn (Message $m) => $m->thread->broadcastAs('created')],
    'thread per-participant channel' => ['messages.broadcasting.threads.per-participant-channel', fn (Message $m) => $m->thread->broadcastOn('created')],
]);

it('uses the shipped broadcast names when they are absent (strict config)', function (): void {
    config()->set('messages.broadcasting.enabled', true);
    config()->set('messages.broadcasting.messages.channel', null);
    config()->set('messages.broadcasting.messages.events.created', null);
    $message = strictMessage();

    expect($message->broadcastOn('created')->name)->toBe('private-messaging.thread.'.$message->thread_id)
        ->and($message->broadcastAs('created'))->toBe('messaging.message.sent');
});

it('refuses a notification class or channel list that does not fit (strict config)', function (string $key, mixed $value): void {
    config()->set('messages.notifications.enabled', true);
    config()->set($key, $value);

    $sender = NotifiableUser::create();
    $thread = Messages::start('Chat')->withParticipant($sender)->withParticipant(NotifiableUser::create())->create();

    expect(fn () => Messages::to($thread)->from($sender)->send('hi'))
        ->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'notification not a class' => ['messages.notifications.notification', 'App\\Missing\\Notification'],
    'notification not a notification' => ['messages.notifications.notification', User::class],
    'channels string' => ['messages.notifications.channels', 'database'],
    'channels junk entry' => ['messages.notifications.channels', ['database', '']],
]);

it('flags a broken setting in about instead of rendering a fallback (strict config)', function (): void {
    config()->set('messages.preview.length', 'long');
    config()->set('messages.prune.days', 'ninety');
    config()->set('messages.media.visibility', 'pubic');
    config()->set('messages.media.max_file_size', '2MB');
    config()->set('messages.media.temporary_url_lifetime', 'soon');

    Artisan::call('about', ['--only' => 'messages']);
    $output = Artisan::output();

    expect($output)->toMatch('/Preview length\W+INVALID/')
        ->and($output)->toMatch('/Prune retention\W+INVALID/')
        ->and($output)->toMatch('/Attachments\W+INVALID/')
        ->and($output)->toMatch('/Max attachment size\W+INVALID/')
        ->and($output)->toMatch('/Signed URL lifetime\W+INVALID/')
        ->and($output)->not->toContain('120 chars');
});
