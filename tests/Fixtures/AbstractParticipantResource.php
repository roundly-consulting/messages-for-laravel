<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A JsonResource subclass that cannot be instantiated — refused as `messages.resources.participant`.
 */
abstract class AbstractParticipantResource extends JsonResource {}
