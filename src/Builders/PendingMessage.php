<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

final class PendingMessage
{
    private ?Model $sender = null;

    private MessageType $type = MessageType::Text;

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly Thread $thread,
    ) {}

    public function from(Model $sender): self
    {
        $this->sender = $sender;

        return $this;
    }

    public function ofType(MessageType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function send(string $body): Message
    {
        return $this->sendMessage->execute(new SendMessageData(
            thread: $this->thread,
            sender: $this->sender,
            body: $body,
            type: $this->type,
        ));
    }
}
