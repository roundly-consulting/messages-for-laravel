<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing conversations from `messages.models.thread`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class ThreadModel
{
    /**
     * @return class-string<Thread>
     */
    public static function class(): string
    {
        return ModelResolver::for('messages.models.thread', Thread::class);
    }
}
