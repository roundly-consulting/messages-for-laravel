<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Models\Thread;

/**
 * @mixin Thread
 */
final class ThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_direct' => $this->is_direct,
            'is_public' => $this->is_public,
            'is_archived' => $this->archived_at !== null,
            'last_activity_at' => $this->last_activity_at->toIso8601String(),
            'unread_count' => $this->when(
                $this->resource->getAttribute('unread_count') !== null,
                fn (): int => (int) $this->resource->getAttribute('unread_count'),
            ),
            'latest_message' => MessageResource::make($this->whenLoaded('latestMessage')),
            'participants' => ParticipantResource::collection($this->whenLoaded('participants')),
        ];
    }
}
