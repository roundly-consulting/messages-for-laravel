<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Models\Message;

final class PruneMessages
{
    /**
     * Force-delete messages older than the cutoff. Returns the number removed.
     */
    public function execute(PruneMessagesData $data): int
    {
        /** @var class-string<Message> $model */
        $model = config('messages.models.message', Message::class);

        $cutoff = now()->subDays($data->days);

        $query = $model::query()
            ->withTrashed()
            ->where('created_at', '<', $cutoff)
            ->when($data->threadId !== null, fn ($query) => $query->where('thread_id', $data->threadId));

        $this->clearAttachments($query->clone());

        // Bulk force-delete skips model events, so the forceDeleted cleanup hook never fires here.
        return $query->forceDelete();
    }

    /**
     * Remove the attachment files of every message about to be pruned. The model's forceDeleted
     * hook does not fire on a bulk delete, so cleanup happens explicitly here.
     *
     * @param  Builder<Message>  $query
     */
    private function clearAttachments(Builder $query): void
    {
        if (! (bool) config('messages.media.cleanup_on_force_delete', true)) {
            return;
        }

        $query->each(static function (Message $message): void {
            $message->clearMediaBucket($message->attachmentsBucket());
        });
    }
}
