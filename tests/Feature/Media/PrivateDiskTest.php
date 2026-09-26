<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Tests\Models\User;

/**
 * Signed URLs only protect the link. Private attachments (the default) used to be written to
 * media-library's default disk — the web-served `public` disk — so a DM attachment sat under
 * /storage for anyone with its path, whatever the config promised ("reachable only through
 * media's signed streaming route"). They now default to `messages.media.private_disk` ('local').
 */
beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

function privateAttachment(UploadedFile $file): array
{
    $message = Messages::to(messaging()->threads()->create(name: 'Chat'))->from(User::create())->send('hi');

    return [$message, $message->addMedia($file)->toMediaBucket('attachments')];
}

it('stores private attachments and their variants on the private disk', function (): void {
    config()->set('media.variants_disk', 'public');

    /** @var Media $media */
    [, $media] = privateAttachment(UploadedFile::fake()->image('a.jpg', 800, 600));

    expect($media->isPrivate())->toBeTrue()
        ->and($media->disk)->toBe('local')
        ->and($media->diskFor('responsive-320'))->toBe('local')
        ->and(Storage::disk('local')->exists($media->getPath()))->toBeTrue()
        ->and(Storage::disk('local')->exists($media->getPath('responsive-320')))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('honours a configured private disk, an explicit disk and public visibility', function (): void {
    config()->set('filesystems.disks.vault', ['driver' => 'local', 'root' => storage_path('app/vault')]);
    Storage::fake('vault');
    config()->set('messages.media.private_disk', 'vault');

    [, $vault] = privateAttachment(UploadedFile::fake()->create('a.pdf', 12, 'application/pdf'));

    config()->set('messages.media.disk', 'public');
    [, $explicit] = privateAttachment(UploadedFile::fake()->create('b.pdf', 12, 'application/pdf'));

    config()->set('messages.media.disk', null);
    config()->set('messages.media.visibility', 'public');
    [, $public] = privateAttachment(UploadedFile::fake()->create('c.pdf', 12, 'application/pdf'));

    expect($vault->disk)->toBe('vault')
        ->and($explicit->disk)->toBe('public')
        ->and($public->disk)->toBe('public');
});

it('serves a private attachment from the real private disk through the signed stream route', function (): void {
    // A real (not faked) local disk: no native temporary URLs, so media mints its own signed
    // streaming route. It must stream the bytes from the private disk and refuse a tampered link.
    $root = sys_get_temp_dir().'/messages-private-'.bin2hex(random_bytes(4));
    config()->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root]);
    Storage::forgetDisk('local');

    try {
        [$message, $media] = privateAttachment(UploadedFile::fake()->createWithContent('note.txt', 'dm bytes'));
        $url = $message->attachmentUrl($media);

        expect($media->disk)->toBe('local')
            ->and(is_file($root.'/'.$media->getPath()))->toBeTrue()
            ->and(Storage::disk('public')->exists($media->getPath()))->toBeFalse()
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();

        $response = $this->get($url);

        $response->assertOk();
        expect($response->streamedContent())->toBe('dm bytes');

        $this->get($url.'0')->assertForbidden();
    } finally {
        Storage::forgetDisk('local');
        File::deleteDirectory($root);
    }
});
