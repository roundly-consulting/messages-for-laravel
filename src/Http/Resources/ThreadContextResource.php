<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Messages\Support\MessagesConfig;

/**
 * A thread's context — a host model such as `App\Models\Company` — as a client sees it: its key
 * and class basename (`{"id": 7, "type": "Company"}`). Never a class name.
 *
 * `ThreadResource::context` renders through `messages.resources.context`, which defaults to this
 * class: point it at your own JsonResource (it receives the context model) to add what your API
 * needs.
 *
 * @property Model $resource
 */
final class ThreadContextResource extends JsonResource
{
    /**
     * The configured context resource (`messages.resources.context`) around a context model;
     * `null` when there is none, so a parent renders `null`.
     */
    public static function configured(?Model $context): ?JsonResource
    {
        if ($context === null) {
            return null;
        }

        $resource = MessagesConfig::contextResource();

        return new $resource($context);
    }

    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'type' => class_basename($this->resource),
        ];
    }
}
