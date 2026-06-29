<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
});

function attachmentMessage(): Message
{
    $user = User::create();
    $thread = messaging()->threads()->create(name: 'Chat');

    return Messages::to($thread)->from($user)->send('hi');
}

it('collects every attachment regardless of type', function (): void {
    $message = attachmentMessage();

    $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');
    $message->addMedia(UploadedFile::fake()->image('b.png', 400, 300))->toMediaBucket('attachments');
    $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))->toMediaBucket('attachments');

    expect($message->attachments())->toHaveCount(3)
        ->and($message->hasAttachments())->toBeTrue();
});

it('splits image and non-image attachments', function (): void {
    $message = attachmentMessage();

    $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');
    $message->addMedia(UploadedFile::fake()->image('b.png', 400, 300))->toMediaBucket('attachments');
    $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))->toMediaBucket('attachments');

    expect($message->imageAttachments())->toHaveCount(2)
        ->and($message->fileAttachments())->toHaveCount(1)
        ->and($message->fileAttachments()->first()->file_name)->toContain('brief');
});

it('stores attachments as private by default', function (): void {
    $message = attachmentMessage();

    $media = $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');

    expect($media->isPrivate())->toBeTrue()
        ->and($media->isPublic())->toBeFalse();
});

it('accepts a non-image file without throwing', function (): void {
    $message = attachmentMessage();

    $media = $message->addMedia(UploadedFile::fake()->create('archive.zip', 20, 'application/zip'))
        ->toMediaBucket('attachments');

    expect($media->isImage())->toBeFalse()
        ->and($message->fileAttachments())->toHaveCount(1);
});

it('rejects a disallowed mime type when accepted types are configured', function (): void {
    config()->set('messages.media.accepted_mime_types', ['image/jpeg']);

    $message = attachmentMessage();

    $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))
        ->toMediaBucket('attachments');
})->throws(FileUnacceptableForBucket::class);

it('reports an empty bucket as having no attachments', function (): void {
    $message = attachmentMessage();

    expect($message->hasAttachments())->toBeFalse()
        ->and($message->attachments())->toBeEmpty()
        ->and($message->imageAttachments())->toBeEmpty()
        ->and($message->fileAttachments())->toBeEmpty();
});

it('honours a custom attachments bucket name from config', function (): void {
    config()->set('messages.media.attachments_bucket', 'files');

    $message = attachmentMessage();

    expect($message->attachmentsBucket())->toBe('files');

    $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('files');

    expect($message->attachments())->toHaveCount(1);
});

it('stores attachments publicly when visibility is configured public', function (): void {
    config()->set('messages.media.visibility', 'public');

    $message = attachmentMessage();

    $media = $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');

    expect($media->isPublic())->toBeTrue();
});
