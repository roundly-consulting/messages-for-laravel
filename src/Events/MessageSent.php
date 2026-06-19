<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Events;

use RoundlyConsulting\Messages\Models\Message;

final class MessageSent
{
    public function __construct(
        public readonly Message $message,
    ) {}
}
