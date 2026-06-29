<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Models\Message;

final class SendMessage
{
    public function execute(SendMessageData $data): Message
    {
        $meta = $data->meta;

        if ($data->parentMessageId !== null) {
            $meta = $this->withQuoteSnapshot($data, $meta);
        }

        // Create the message and bind its attachments atomically: a bad draft token (or any
        // attachment failure) rolls the whole send back, so no bodyless/orphan message is left.
        $message = DB::transaction(function () use ($data, $meta): Message {
            /** @var Message $message */
            $message = $data->thread->messages()->create([
                'parent_message_id' => $data->parentMessageId,
                'sender_id' => $data->sender?->getKey(),
                'sender_type' => $data->sender?->getMorphClass(),
                'message' => $data->body,
                'type' => $data->type,
                'meta' => $meta === [] ? null : $meta,
            ]);

            $this->bindAttachments($message, $data);

            $data->thread->touch('last_activity_at');

            return $message;
        });

        // Dispatch after commit so listeners, broadcasts, and recipients see the attachments.
        Event::dispatch(new MessageSent($message));

        return $message;
    }

    /**
     * Bind draft media tokens and uploaded files to the message's attachments bucket before the
     * MessageSent event fires.
     */
    private function bindAttachments(Message $message, SendMessageData $data): void
    {
        if ($data->attachments === [] && $data->uploads === []) {
            return;
        }

        $bucket = $message->attachmentsBucket();

        foreach ($data->attachments as $token) {
            $message->attachDraftMedia($token, $bucket);
        }

        foreach ($data->uploads as $upload) {
            $message->addMedia($upload)->toMediaBucket($bucket);
        }
    }

    /**
     * Build a lightweight snapshot of the quoted parent so the quote survives the parent
     * being edited or deleted later.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withQuoteSnapshot(SendMessageData $data, array $meta): array
    {
        /** @var class-string<Message> $model */
        $model = config('messages.models.message', Message::class);

        $parent = $model::query()->find($data->parentMessageId);

        if (! $parent instanceof Message) {
            throw MessageException::replyAcrossThreads();
        }

        if ($parent->thread_id !== $data->thread->getKey()) {
            throw MessageException::replyAcrossThreads();
        }

        $meta['quote'] = [
            'id' => $parent->getKey(),
            'sender_id' => $parent->sender_id,
            'sender_type' => $parent->sender_type,
            'excerpt' => Str::limit($parent->message, (int) config('messages.preview.length', 120)),
        ];

        return $meta;
    }
}
