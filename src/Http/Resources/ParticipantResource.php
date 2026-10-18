<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Models\Participant;

/**
 * @mixin Participant
 */
final class ParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role?->value,
            'read_at' => $this->read_at?->toIso8601String(),
            'joined_at' => $this->created_at->toIso8601String(),
            // Only once the relation is loaded, through `messages.resources.participant`; `null`
            // when the participating model no longer exists.
            'participant' => $this->when(
                $this->resource->relationLoaded('participant'),
                fn (): ?array => MessageParticipantResource::configured($this->participant)?->resolve($request),
            ),
        ];
    }
}
