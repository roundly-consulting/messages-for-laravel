<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Interfaces\ParticipatesInMessaging;
use RoundlyConsulting\Messages\Support\MessagesConfig;

/**
 * A participant or message sender — a host model such as `App\Models\User` — as a client sees it:
 * its {@see ParticipatesInMessaging::participateAs()} snapshot when it implements the interface,
 * otherwise its key and class basename (`{"id": 5, "type": "User"}`). Never a class name.
 *
 * `ParticipantResource::participant` and `MessageResource::sender` render through
 * `messages.resources.participant`, which defaults to this class: point it at your own
 * JsonResource (it receives the model) to add what your API needs.
 *
 * @property Model $resource
 */
final class MessageParticipantResource extends JsonResource
{
    /**
     * The configured participant resource (`messages.resources.participant`) around a
     * participant or sender model; `null` when there is none (a system message's sender, a
     * participant whose model was deleted), so a parent renders `null`.
     */
    public static function configured(?Model $participant): ?JsonResource
    {
        if ($participant === null) {
            return null;
        }

        $resource = MessagesConfig::participantResource();

        return new $resource($participant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof ParticipatesInMessaging) {
            return $this->resource->participateAs();
        }

        return [
            'id' => $this->resource->getKey(),
            'type' => class_basename($this->resource),
        ];
    }
}
