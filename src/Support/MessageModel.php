<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing messages from `messages.models.message`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class MessageModel
{
    /**
     * @return class-string<Message>
     */
    public static function class(): string
    {
        return ModelResolver::for('messages.models.message', Message::class);
    }
}
