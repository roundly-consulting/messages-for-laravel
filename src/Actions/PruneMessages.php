<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Messages\DataTransferObjects\PruneMessagesData;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class PruneMessages
{
    /**
     * Force-delete messages older than the cutoff. Returns the number removed.
     */
    public function execute(PruneMessagesData $data): int
    {
        $model = MessageModel::class();

        $cutoff = now()->subDays($data->days);

        $query = $model::query()
            ->withTrashed()
            ->where('created_at', '<', $cutoff)
            ->when($data->threadId !== null, fn ($query) => $query->where('thread_id', $data->threadId));

        $this->clearAttachments($query->clone());

        // The threads about to lose messages, captured BEFORE the delete — afterwards the rows
        // are gone and there is nothing left to ask.
        $threadIds = $query->clone()->distinct()->pluck('thread_id')->all();

        // Bulk force-delete skips model events, so the forceDeleted cleanup hook never fires here.
        $pruned = $query->forceDelete();

        // ...and for the same reason MaintainsThreadLatestMessage never fires either. A pruned
        // thread whose newest message was just deleted would otherwise keep pointing at it, and
        // the inbox would show a preview of a message that no longer exists.
        foreach ($threadIds as $threadId) {
            MessageModel::class()::syncLatestMessageFor($threadId);
        }

        return $pruned;
    }

    /**
     * Remove the attachment files of every message about to be pruned. The model's forceDeleted
     * hook does not fire on a bulk delete, so cleanup happens explicitly here.
     *
     * @param  Builder<Message>  $query
     */
    private function clearAttachments(Builder $query): void
    {
        if (! Config::boolean('messages.media.cleanup_on_force_delete', true)) {
            return;
        }

        $query->each(static function (Message $message): void {
            $message->clearMediaBucket($message->attachmentsBucket());
        });
    }
}
