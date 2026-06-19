<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
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
        $participant = $this->whenLoaded('participant');

        return [
            'id' => $this->id,
            'role' => $this->role?->value,
            'read_at' => $this->read_at?->toIso8601String(),
            'joined_at' => $this->created_at->toIso8601String(),
            'participant' => $participant instanceof ParticipatesInMessaging
                ? $participant->participateAs()
                : $this->when(
                    $this->resource->relationLoaded('participant'),
                    fn (): array => [
                        'id' => $this->participant_id,
                        'type' => $this->participant_type,
                    ],
                ),
        ];
    }
}
