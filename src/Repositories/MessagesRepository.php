<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

final class MessagesRepository
{
    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function sendMessage(Thread $thread, Model $sender, string $message): Message
    {
        return $this->sendMessage->execute(new SendMessageData(
            thread: $thread,
            sender: $sender,
            body: $message,
        ));
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
