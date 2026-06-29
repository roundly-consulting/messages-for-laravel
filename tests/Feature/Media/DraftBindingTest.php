<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Facades\Media;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
});

function draftToken(string $name = 'draft.jpg'): string
{
    return (string) Media::draft(UploadedFile::fake()->image($name, 400, 300))
        ->toBucket('attachments')
        ->draft_token;
}

it('binds a draft media token to the sent message', function (): void {
    $token = draftToken();
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from(User::create())
        ->withAttachment($token)
        ->send('with a draft');

    expect($message->attachments())->toHaveCount(1);

    $bound = $message->attachments()->first();
    expect($bound->draft_token)->toBeNull()
        ->and($bound->bucket_name)->toBe('attachments');
});

it('binds several draft tokens at once', function (): void {
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from(User::create())
        ->withAttachments([draftToken('one.jpg'), draftToken('two.jpg')])
        ->send('two drafts');

    expect($message->attachments())->toHaveCount(2);
});

it('attaches an uploaded file on send', function (): void {
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from(User::create())
        ->attach(UploadedFile::fake()->image('upload.jpg', 400, 300))
        ->send('with an upload');

    expect($message->attachments())->toHaveCount(1)
        ->and($message->attachments()->first()->file_name)->toContain('upload');
});

it('exposes attachments to MessageSent listeners', function (): void {
    $token = draftToken();
    $thread = messaging()->threads()->create(name: 'Chat');

    $seen = null;
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$seen): void {
        $seen = $event->message->attachments()->count();
    });

    Messages::to($thread)->from(User::create())->withAttachment($token)->send('hi');

    expect($seen)->toBe(1);
});

it('rolls the send back when a draft token is unknown', function (): void {
    $thread = messaging()->threads()->create(name: 'Chat');

    try {
        Messages::to($thread)->from(User::create())
            ->withAttachment('does-not-exist')
            ->send('doomed');

        $this->fail('Expected DraftMediaNotFound to be thrown.');
    } catch (DraftMediaNotFound) {
        // The whole send is atomic: no orphan message is persisted.
        expect(Message::query()->count())->toBe(0);
    }
});

it('sends a plain message unchanged when no attachments are given', function (): void {
    $thread = messaging()->threads()->create(name: 'Chat');

    $message = Messages::to($thread)->from(User::create())->send('plain');

    expect($message->message)->toBe('plain')
        ->and($message->hasAttachments())->toBeFalse()
        ->and(Message::query()->count())->toBe(1);
});
