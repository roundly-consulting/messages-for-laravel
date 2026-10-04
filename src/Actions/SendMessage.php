<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Messages\DataTransferObjects\SendMessageData;
use RoundlyConsulting\Messages\Events\MessageSent;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Exceptions\UnauthorizedMessagingAction;
use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\Messages\Support\MessageModel;
use RoundlyConsulting\Messages\Support\MessagesConfig;

final class SendMessage
{
    public function execute(SendMessageData $data): Message
    {
        $this->authorize($data);

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

            // `latestMessage` is a belongsTo over `threads.last_message_id`, so it answers from
            // the thread instance's own attribute. The message's `created` hook has already
            // written the pointer to the database — and a just-sent message is always the
            // newest (stamped `now()`, greatest key), so that value is this message's id. The
            // caller's `$data->thread` object still holds the pointer it was loaded with,
            // though; catch it up with the same id, or `$thread->latestMessage` would report
            // the thread as empty immediately after a successful send on it.
            MessageModel::class()::applyLatestMessageTo($data->thread, $message->getKey());

            return $message;
        });

        // Dispatch after commit so listeners, broadcasts, and recipients see the attachments.
        Event::dispatch(new MessageSent($message));

        return $message;
    }

    /**
     * Only a current participant may post — the same rule every other write applies to its
     * actor. A left or removed participant's row is soft-deleted, so the relation's own scope
     * refuses them too. A system message has no sender and is always allowed.
     *
     * @throws UnauthorizedMessagingAction
     */
    private function authorize(SendMessageData $data): void
    {
        if ($data->sender === null || ! $data->requireParticipation) {
            return;
        }

        $participates = $data->thread
            ->participants()
            ->whereMorphedTo('participant', $data->sender)
            ->exists();

        if (! $participates) {
            throw UnauthorizedMessagingAction::for($data->sender, 'messages::messages.permissions.actions.send-messages');
        }
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
        $model = MessageModel::class();

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
            'excerpt' => Str::limit($parent->message, MessagesConfig::previewLength()),
        ];

        return $meta;
    }
}
