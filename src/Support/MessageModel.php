<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Support;

use RoundlyConsulting\Messages\Models\Message;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing messages from `messages.models.message`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; anything that is not a Message (so it cannot answer the
 * package's scopes, previews and media bucket) falls back to the packaged model.
 */
final class MessageModel
{
    /**
     * @return class-string<Message>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('messages.models.message', Message::class);

        return is_a($model, Message::class, true) ? $model : Message::class;
    }
}
