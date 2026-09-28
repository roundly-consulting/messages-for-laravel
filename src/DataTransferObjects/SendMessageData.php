<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Models\Thread;

final readonly class SendMessageData
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $attachments  Draft media tokens bound to the message on send.
     * @param  list<UploadedFile>  $uploads  Uploaded files attached to the message on send.
     * @param  bool  $requireParticipation  Refuse a sender who is not a current participant of the
     *                                      thread. Only trusted server code posting as an outsider
     *                                      (a bot, a support agent) turns it off.
     */
    public function __construct(
        public Thread $thread,
        public ?Model $sender,
        public string $body,
        public MessageType $type = MessageType::Text,
        public array $meta = [],
        public int|string|null $parentMessageId = null,
        public array $attachments = [],
        public array $uploads = [],
        public bool $requireParticipation = true,
    ) {}
}
