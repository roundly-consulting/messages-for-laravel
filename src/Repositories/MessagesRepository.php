<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

final class MessagesRepository
{
    public function sendMessage(Thread $thread, Model $sender, string $message): Message
    {
        $created = $thread->messages()->create([
            'sender_id' => $sender->getKey(),
            'sender_type' => $sender->getMorphClass(),
            'message' => $message,
        ]);

        $thread->touch('last_activity_at');

        return $created;
    }

    /**
     * @return LengthAwarePaginator<int, Message>
     */
    public function paginate(
        Thread $thread,
        int $page = 1,
        int $perPage = 10,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        return $thread->messages()
            ->with('sender')
            ->latest()
            ->paginate(
                perPage: $perPage,
                pageName: $pageName,
                page: $page,
            );
    }
}
