<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Messages\Actions\DeleteMessage;
use RoundlyConsulting\Messages\Actions\PruneMessages;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('messages.media.disk', 'secure');
    Storage::disk('secure')->deleteDirectory('');
});

function messageWithAttachment(string $name = 'a.jpg'): array
{
    $thread = Messages::start('Chat')->create();
    $message = Messages::to($thread)->from(User::create())->send('hi');
    $media = $message->addMedia(UploadedFile::fake()->image($name, 400, 300))->toMediaBucket('attachments');

    return [$message, $media];
}

it('removes attachment media and files on force delete', function (): void {
    [$message, $media] = messageWithAttachment();
    $path = $media->getPath();

    expect(Storage::disk('secure')->exists($path))->toBeTrue();

    $message->forceDelete();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('secure')->exists($path))->toBeFalse();
});

it('keeps attachment files on a soft delete', function (): void {
    [$message, $media] = messageWithAttachment('keep.jpg');
    $path = $media->getPath();

    app(DeleteMessage::class)->execute($message);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(Storage::disk('secure')->exists($path))->toBeTrue();
});

it('keeps attachments on force delete when cleanup is disabled', function (): void {
    config()->set('messages.media.cleanup_on_force_delete', false);

    [$message, $media] = messageWithAttachment('retain.jpg');
    $path = $media->getPath();

    $message->forceDelete();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(Storage::disk('secure')->exists($path))->toBeTrue();
});

it('removes attachments of pruned messages', function (): void {
    [$message, $media] = messageWithAttachment('prune.jpg');
    $path = $media->getPath();

    // Backdate the message past the retention window.
    Message::query()->whereKey($message->getKey())->update(['created_at' => now()->subDays(120)]);

    $removed = app(PruneMessages::class)->execute(new PruneMessagesData(days: 90));

    expect($removed)->toBe(1)
        ->and(Media::query()->whereKey($media->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('secure')->exists($path))->toBeFalse();
});
