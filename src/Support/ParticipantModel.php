<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing thread membership from
 * `messages.models.participant`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; anything that is not a Participant (so it cannot answer the
 * package's read pointers and role checks) falls back to the packaged model.
 */
final class ParticipantModel
{
    /**
     * @return class-string<Participant>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('messages.models.participant', Participant::class);

        return is_a($model, Participant::class, true) ? $model : Participant::class;
    }

    public static function new(): Participant
    {
        $model = self::class();

        return new $model;
    }
}
