<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Thread;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing conversations from `messages.models.thread`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; anything that is not a Thread (so it cannot answer the
 * package's scopes, relations and read-state methods) falls back to the
 * packaged model.
 */
final class ThreadModel
{
    /**
     * @return class-string<Thread>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('messages.models.thread', Thread::class);

        return is_a($model, Thread::class, true) ? $model : Thread::class;
    }
}
