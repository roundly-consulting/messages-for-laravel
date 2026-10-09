<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Enums\MessageType;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Models\Message;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sender = $this->whenLoaded('sender');
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'type' => $this->type->value,
            'body' => $this->deleted_at !== null ? null : $this->message,
            'preview' => $this->preview(),
            'is_deleted' => $this->deleted_at !== null,
            'sender' => $sender instanceof ParticipatesInMessaging
                ? $sender->participateAs()
                : $this->when(
                    $this->resource->relationLoaded('sender'),
                    fn (): ?array => null,
                ),
            'reply_to' => $this->replyInfo($meta),
            'sent_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->editedAt($meta),
        ];
    }

    /**
     * When the text was last reworded — recorded by the model in `meta.edited_at` when its body
     * changes. Not `updated_at`, which an unsend and a restore stamp too.
     *
     * @param  array<string, mixed>  $meta
     */
    private function editedAt(array $meta): ?string
    {
        $editedAt = $meta['edited_at'] ?? null;

        if ($this->type !== MessageType::Text || ! is_string($editedAt) || $editedAt === '') {
            return null;
        }

        return $editedAt;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function replyInfo(array $meta): ?array
    {
        if ($this->parent_message_id === null) {
            return null;
        }

        $quote = is_array($meta['quote'] ?? null) ? $meta['quote'] : [];

        return [
            'id' => $this->parent_message_id,
            'excerpt' => $quote['excerpt'] ?? null,
            'sender_id' => $quote['sender_id'] ?? null,
            'sender_type' => $quote['sender_type'] ?? null,
        ];
    }
}
