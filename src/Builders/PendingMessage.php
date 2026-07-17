<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Messages\Actions\SendMessage;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Models\Thread;

final class PendingMessage
{
    private ?Model $sender = null;

    private MessageType $type = MessageType::Text;

    private int|string|null $parentMessageId = null;

    private ?string $systemKey = null;

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var list<string> */
    private array $attachments = [];

    /** @var list<UploadedFile> */
    private array $uploads = [];

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

    public function replyingTo(Message $message): self
    {
        $this->parentMessageId = $message->getKey();

        return $this;
    }

    /**
     * Prepare a translatable system message (sent without a sender on ->send()).
     *
     * @param  array<string, scalar>  $params
     */
    public function asSystem(string $key, array $params = []): self
    {
        $this->type = MessageType::System;
        $this->systemKey = $key;
        $this->sender = null;
        $this->meta = [...$this->meta, ...$params];

        return $this;
    }

    /** @param  array<string, mixed>  $meta */
    public function withMeta(array $meta): self
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /** Bind a previously-uploaded draft media (by its token) to the message's attachments bucket. */
    public function withAttachment(string $token): self
    {
        $this->attachments[] = $token;

        return $this;
    }

    /**
     * Bind several draft media tokens to the message's attachments bucket.
     *
     * @param  list<string>  $tokens
     */
    public function withAttachments(array $tokens): self
    {
        $this->attachments = [...$this->attachments, ...$tokens];

        return $this;
    }

    /** Attach an uploaded file to the message's attachments bucket. */
    public function attach(UploadedFile $file): self
    {
        $this->uploads[] = $file;

        return $this;
    }

    public function send(?string $body = null): Message
    {
        $resolvedBody = $body ?? $this->systemKey;

        return $this->sendMessage->execute(new SendMessageData(
            thread: $this->thread,
            sender: $this->sender,
            body: $resolvedBody ?? '',
            type: $this->type,
            meta: $this->meta,
            parentMessageId: $this->parentMessageId,
            attachments: $this->attachments,
            uploads: $this->uploads,
        ));
    }
}
