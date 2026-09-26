<?php

declare(strict_types=1);

use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Facades\Messages;
use RoundlyConsulting\Messages\Listeners\WarmMessageMediaVariants;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Tests\Models\User;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

function sentMessage(): Message
{
    $thread = messaging()->threads()->create(name: 'Chat');

    return $thread->messages()->create([
        'sender_id' => User::create()->id,
        'sender_type' => User::class,
        'message' => 'hi',
    ]);
}

it('queues one GenerateVariantsJob per image attachment', function (): void {
    Queue::fake();

    $message = sentMessage();
    $one = $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');
    $two = $message->addMedia(UploadedFile::fake()->image('b.jpg', 400, 300))->toMediaBucket('attachments');

    (new WarmMessageMediaVariants)->handle(new MessageSent($message));

    Queue::assertPushed(GenerateVariantsJob::class, 2);
    Queue::assertPushed(
        GenerateVariantsJob::class,
        fn (GenerateVariantsJob $job): bool => in_array($job->mediaId, [(int) $one->getKey(), (int) $two->getKey()], true),
    );
});

it('queues nothing for a message with only non-image attachments', function (): void {
    Queue::fake();

    $message = sentMessage();
    $message->addMedia(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'))->toMediaBucket('attachments');

    (new WarmMessageMediaVariants)->handle(new MessageSent($message));

    Queue::assertNotPushed(GenerateVariantsJob::class);
});

it('queues nothing when warming is disabled', function (): void {
    config()->set('messages.media.warm_on_send', false);
    Queue::fake();

    $message = sentMessage();
    $message->addMedia(UploadedFile::fake()->image('a.jpg', 400, 300))->toMediaBucket('attachments');

    (new WarmMessageMediaVariants)->handle(new MessageSent($message));

    Queue::assertNotPushed(GenerateVariantsJob::class);
});

it('queues the warm-variants listener when a message is sent', function (): void {
    Queue::fake();

    $thread = messaging()->threads()->create(name: 'Chat');
    $message = Messages::to($thread)->from(User::create())
        ->attach(UploadedFile::fake()->image('upload.jpg', 400, 300))
        ->send('with an image');

    expect($message->imageAttachments())->toHaveCount(1);

    // The listener is ShouldQueue, so MessageSent pushes it onto the queue (it then dispatches
    // a GenerateVariantsJob per image when it runs — covered by the unit tests above).
    Queue::assertPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job): bool => $job->class === WarmMessageMediaVariants::class,
    );
});
