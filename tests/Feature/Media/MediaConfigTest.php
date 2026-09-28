<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

function configuredMessage(): Message
{
    $thread = Messages::start('Chat')->create();

    return Messages::to($thread)->from(User::create())->send('hi');
}

it('feeds the configured maximum file size into the bucket validation rules', function (): void {
    config()->set('messages.media.max_file_size', 2 * 1024 * 1024); // 2 MB

    $rules = MediaLibrary::rulesFor(Message::class, 'attachments');

    // 2 MB / 1024 = a 2048 KB `max:` rule.
    expect($rules)->toContain('max:2048');
});

it('applies an explicit responsive width ladder for image attachments', function (): void {
    config()->set('messages.media.responsive_widths', [120, 240]);

    $message = configuredMessage();
    $media = $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');

    $names = array_map(static fn (object $variant): string => $variant->name, $media->resolveVariants());

    // The explicit ladder produces a `responsive-{width}` variant per configured width.
    expect($names)->toContain('responsive-120')
        ->and($names)->toContain('responsive-240');
});
