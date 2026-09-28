<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');

    // Store attachments on a real local disk (no native temporaryUrl) so the signed streaming
    // route is exercised, exactly as a private DM disk behaves in production.
    Storage::disk('secure')->deleteDirectory('');
    config()->set('messages.media.disk', 'secure');
});

function messageWithImage(string $name = 'photo.jpg'): array
{
    $thread = Messages::start('Chat')->create();
    $message = Messages::to($thread)->from(User::create())->send('hi');
    $media = $message->addMedia(UploadedFile::fake()->image($name, 400, 300))->toMediaBucket('attachments');

    return [$message, $media];
}

it('mints a signed URL for a private attachment that streams through the route', function (): void {
    [$message, $media] = messageWithImage();

    $url = $message->attachmentUrl($media);

    expect($url)->toContain('signature=')
        ->and($url)->toContain('/media/');

    $this->get($url)->assertOk();
});

it('rejects a tampered signature', function (): void {
    [$message, $media] = messageWithImage('tampered.jpg');

    $url = $message->attachmentUrl($media);

    $this->get($url.'&download=1')->assertForbidden();
});

it('forces a download through a signed download URL', function (): void {
    [$message, $media] = messageWithImage('doc.jpg');

    $download = $this->get($message->attachmentDownloadUrl($media));

    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment');
});

it('returns the original preview URL when the variant is not warmed', function (): void {
    // media-library falls back to the original variant only when configured to.
    config()->set('media.url_fallback_to_original', true);

    [$message, $media] = messageWithImage('preview.jpg');

    $url = $message->attachmentPreviewUrl($media, '320');

    expect($url)->toContain('signature=');
    // Falls back to the original until the variant is generated.
    $this->get($url)->assertOk();
});

it('refuses a preview URL for a non-image attachment', function (): void {
    $thread = Messages::start('Chat')->create();
    $message = Messages::to($thread)->from(User::create())->send('hi');
    $pdf = $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))
        ->toMediaBucket('attachments');

    $message->attachmentPreviewUrl($pdf);
})->throws(MessageException::class);

it('respects the configured temporary URL lifetime', function (): void {
    config()->set('messages.media.temporary_url_lifetime', 1);

    [$message, $media] = messageWithImage('ttl.jpg');

    $url = $message->attachmentUrl($media);

    $this->get($url)->assertOk();

    $this->travel(2)->minutes();
    $this->get($url)->assertForbidden();

    $this->travelBack();
});

it('streams a non-image attachment through its signed URL', function (): void {
    $thread = Messages::start('Chat')->create();
    $message = Messages::to($thread)->from(User::create())->send('hi');
    /** @var Media $pdf */
    $pdf = $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))
        ->toMediaBucket('attachments');

    $this->get($message->attachmentUrl($pdf))->assertOk();
});
