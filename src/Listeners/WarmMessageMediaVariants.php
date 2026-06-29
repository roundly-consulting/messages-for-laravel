<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Messages\Events\MessageSent;

/**
 * On MessageSent, (re)warm the message's image attachment variants so responsive previews are
 * ready when the message lands: one queued {@see GenerateVariantsJob} per image attachment. No-op
 * when warming is disabled or the message carries no image attachments.
 */
final class WarmMessageMediaVariants implements ShouldQueue
{
    public function handle(MessageSent $event): void
    {
        if (! (bool) config('messages.media.warm_on_send', true)) {
            return;
        }

        foreach ($event->message->imageAttachments() as $media) {
            $this->warm($media);
        }
    }

    private function warm(Media $media): void
    {
        $variantNames = array_map(
            static fn (object $variant): string => $variant->name,
            $media->resolveVariants(),
        );

        if ($variantNames === []) {
            return;
        }

        GenerateVariantsJob::dispatch((int) $media->getKey(), $variantNames);
    }
}
