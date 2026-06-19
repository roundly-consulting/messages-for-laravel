<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

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

        return $model::query()
            ->withTrashed()
            ->where('created_at', '<', $cutoff)
            ->when($data->threadId !== null, fn ($query) => $query->where('thread_id', $data->threadId))
            ->forceDelete();
    }
}
