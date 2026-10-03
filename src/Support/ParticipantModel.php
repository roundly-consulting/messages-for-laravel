<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Participant;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing thread membership from
 * `messages.models.participant`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class ParticipantModel
{
    /**
     * @return class-string<Participant>
     */
    public static function class(): string
    {
        return ModelResolver::for('messages.models.participant', Participant::class);
    }

    public static function new(): Participant
    {
        $model = self::class();

        return new $model;
    }
}
