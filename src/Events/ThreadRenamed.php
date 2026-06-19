<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Events;

use RoundlyConsulting\Messages\Models\Thread;

final class ThreadRenamed
{
    public function __construct(
        public readonly Thread $thread,
        public readonly ?string $previousName,
    ) {}
}
