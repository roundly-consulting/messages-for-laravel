<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A host's own participant resource, swapped in through `messages.resources.participant`.
 *
 * @property Model $resource
 */
final class HostParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->getKey(),
            'kind' => 'host',
        ];
    }
}
